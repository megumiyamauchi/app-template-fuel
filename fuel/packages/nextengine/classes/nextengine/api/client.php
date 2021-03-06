<?php

namespace Nextengine\Api;

require_once __DIR__.DS.'..'.DS.'..'.DS.'neApiClient.php';

/**
 * ネクストエンジンAPIクライアント
 * 
 * 例外による処理の振り分けを実装しているのはClient_Routerクラスなので、そちらを参照。
 */
class Client extends \neApiClient
{
	/**
	 * デフォルトの設定値
	 * @var array
	 */
	protected static $_defaults = array();

	/**
	 * ユーザオブジェクトを格納する
	 * @var array
	 */
	protected $user = null;

	/**
	 * 設定をロードし、_defaultsプロパティに格納しておく
	 * 
	 * @return void
	 */
	public static function _init()
	{
		self::$_defaults = \Config::load('nextengine', true);
	}

	/**
	 * FuelPHP式（ファクトリメソッド）のコンストラクタ
	 * 
	 * 内部は単にコンストラクタを呼ぶだけ
	 *
	 * @param  array      $config 設定値（$_defaultsプロパティをこの値で上書きする）
	 * @return \Nextengine\Api\Client
	 */
	public static function forge(array $config = array())
	{
		$class = new static($config);
		return $class;
	}

	/**
	 * コンストラクタに与えられた設定でデフォルト設定を上書きし、接続に必要な情報を格納する
	 * 
	 * @param  array $config   設定値（この値が優先される）
	 * @param  bool  $redirect リダイレクトの指定が来た時に強制的にリダイレクトをするか否か。デフォルトはfalse(しない)
	 */
	public function __construct(array $config = array(), $redirect = false)
	{
		$config = \Arr::merge(self::$_defaults, $config);

		// NOTE: バッチ処理ではredirect_uriを指定したくない
		//       なぜなら、redirect_uriを指定するとアクセストークンが切れた時に自動的にSDKがリダイレクトしてしまうから。
		//       コンストラクタのredirect_uriにnullを渡せばリダイレクトを自動で発生させないようになる。
		// NOTE: リダイレクトが必要なコントローラでのAPIクライアントは、このクラスを継承したClient_Routerクラスを使用する。
		if(!$redirect) {
			$config['redirect_uri'] = null;
		}

		parent::__construct(
			$config['client_id'],
			$config['client_secret'],
			$config['redirect_uri']);
	}

	/**
	 * ネクストエンジンAPIを叩く
	 * 
	 * #### 親クラスからの拡張点
	 * - userプロパティがセットされており、アクセストークンが更新されたら自動でDBの値を更新する
	 * - レスポンスに含まれるresultフィールドの値がsuccessでないなら、例外を投げるという処理を追加した
	 *
	 * @param string $path         親クラスのメソッドを参照
	 * @param array  $api_params   親クラスのメソッドを参照
	 * @param string $redirect_uri 親クラスのメソッドを参照
	 * @return mixed APIのレスポンス。詳しくは[http://api.next-e.jp/request_url.php]()を参照。
	 * @throws NextengineApiException
	 */
	public function apiExecute($path, $api_params = array(), $redirect_uri = NULL) {
		$before_exec_access_token = $this->_access_token;
		$response = parent::apiExecute($path, $api_params, $redirect_uri);

		// 親クラスのapiExecuteを実行してもリフレッシュトークンがNULLのまま = リフレッシュトークンの設定が無効になっている
		// code: -1, message: このアプリは必ずリフレッシュトークンの利用が許可されている必要があります。
		if(is_null($this->_refresh_token)) {
			$this->failover('-1', 'このアプリは必ずリフレッシュトークンの利用が許可されている必要があります。');
		}

		// NOTE: エラーの種類については→を参照：http://api.next-e.jp/message.php
		//       エラー時の振る舞いについては、本クラスのfailoverメソッドを参照。
		if($response['result'] !== self::RESULT_SUCCESS) {
			$this->failover($response['code'], $response['message']);
		}

		// APIを叩く前後でアクセストークンが変わっていたら、ユーザモデルを更新してDBに反映
		// ただしユーザモデルが格納されていない場合もあるので、その場合はその処理を行わない
		if(!is_null($this->user) && ($before_exec_access_token !== $this->_access_token)) {
			$this->user->access_token  = $this->_access_token;
			$this->user->refresh_token = $this->_refresh_token;
			$this->user->save();
		}

		return $response;
	}

	/**
	 * ネクストエンジンログインが不要なAPIを実行する
	 * 
	 * #### 親クラスからの拡張点
	 * エラー処理を追加
	 *
	 * @param string $path       親クラスのメソッドを参照
	 * @param array  $api_params 親クラスのメソッドを参照
	 * @return mixed APIのレスポンス。詳しくは[http://api.next-e.jp/request_url.php]()を参照。
	 * @throws NextengineApiException
	 */
	public function apiExecuteNoRequiredLogin($path, $api_params = array()) {
		$response = parent::apiExecuteNoRequiredLogin($path, $api_params);

		// NOTE: エラーの種類については→を参照：http://api.next-e.jp/message.php
		//       エラー時の振る舞いについては、本クラスのfailoverメソッドを参照。
		if($response['result'] !== self::RESULT_SUCCESS) {
			$this->failover($response['code'], $response['message']);
		}

		return $response;
	}


	/**
	 * ユーザ情報をセットする
	 * 
	 * @param Model_User $user ユーザのインスタンス
	 * @return void
	 */
	public function setUser(\Model_User $user) {
		// クライアントのアクセストークンがNULL以外で、DBの値と違っていたら、DBを更新
		// NOTE: クライアントのプロパティが最も最新、DBの値はその次に新しい。優先すべきはクライアントのプロパティ。
		if(!is_null($this->_access_token) && ($user->access_token !== $this->_access_token)) {
			$user->access_token = $this->_access_token;
			$user->refresh_token = $this->_refresh_token;
			$user->save();
		}

		// まだAPIを叩いていない状態で、アクセストークンがあるユーザが渡されたら、クライアントのプロパティを更新
		if(is_null($this->_access_token) && !is_null($user->access_token)) {
			$this->_access_token = $user->access_token;
			$this->_refresh_token = $user->refresh_token;
		}

		$this->user = $user;
	}

	/**
	 * ネクストエンジンAPIからエラーが返っていた場合の処理を行う
	 * 
	 * @param  string $code    NextengineApiExceptionに渡すエラーコード
	 * @param  string $message NextengineApiExceptionに渡すエラーメッセージ
	 * @return void
	 * @throws NextengineApiException
	 */
	protected function failover($code, $message) {
		// NOTE: ログにログレベルERRORで書き込む
		$log = '['.$code.']'.$message;
		\Log::error($log);

		// 開発者にメール送信
		$this->reportToDeveloper($code, $message);

		// 受け取ったコード、メッセージを例外としてスローする
		try {
			throw new NextengineApiException($message, $code);
		} catch(NextengineApiException $e) {
			switch($e->getCode()) {
				// 支払い等の理由で利用停止、システムエラー => 営業に問い合わせて下さい画面へリダイレクト
				case '001007':	// [xxxxx]様のネクストエンジンが、次の理由により利用停止になっています。[xxxxx]
				case '002003':	// [xxxxx]様のネクストエンジンが、次の理由により利用停止になっています。[xxxxx]
				case '003003':	// [xxxxx]様のメイン機能が、利用停止です。
					$this->reportToSales($code, $message);
					break;
			}

			// NOTE: 握りつぶさずに投げなおす
			throw $e;
		}
	}

	/**
	 * 開発者にメールを送信する
	 * 
	 * @param  string $code
	 * @param  string $message
	 * @return boolean 送信に思考したらtrue
	 */
	private function reportToDeveloper($code, $message)
	{
		// 開発者にメール送信
		$subject = \Config::get('nextengine.debug.mail_subject');
		$developers = \Config::get('nextengine.debug.developer');
		$body = "[{$code}] {$message}";

		return $this->mailTo($developers, $subject, $body);
	}

	/**
	 * 営業にメールを送信する
	 * 
	 * @param  string $code
	 * @param  string $message
	 * @return boolean 送信に思考したらtrue
	 */
	private function reportToSales($code, $message)
	{
		$subject = \Config::get('nextengine.debug.mail_subject');
		$sales = \Config::get('nextengine.debug.sales');
		$body = "[{$code}] {$message}";

		return $this->mailTo($sales, $subject, $body);
	}

	/**
	 * メール送信ユーティリティ
	 * 
	 * @param  string|array $to      宛先（配列で複数指定可能）
	 * @param  string       $subject 件名
	 * @param  string       $body    本文
	 * @param  string       $from    送信元（省略するとconfigにあるデフォルトのfromが適用される）
	 * @return boolean 送信に成功したらtrue
	 */
	private function mailTo($to, $subject, $body, $from = null)
	{
		$email = \Email::forge();

		if(!is_null($from)) {
			$email->from($from);
		}

		$email->to($to);
		$email->subject($subject);
		$email->body($body);

		return $email->send();
	}
}
