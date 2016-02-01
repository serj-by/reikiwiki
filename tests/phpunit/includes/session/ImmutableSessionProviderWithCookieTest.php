<?php

namespace MediaWiki\Session;

use MediaWikiTestCase;
use User;

/**
 * @group Session
 * @group Database
 * @covers MediaWiki\Session\ImmutableSessionProviderWithCookie
 */
class ImmutableSessionProviderWithCookieTest extends MediaWikiTestCase {

	private function getProvider( $name, $prefix = null ) {
		$config = new \HashConfig();
		$config->set( 'CookiePrefix', 'wgCookiePrefix' );

		$params = array(
			'sessionCookieName' => $name,
			'sessionCookieOptions' => array(),
		);
		if ( $prefix !== null ) {
			$params['sessionCookieOptions']['prefix'] = $prefix;
		}

		$provider = $this->getMockBuilder( 'MediaWiki\\Session\\ImmutableSessionProviderWithCookie' )
			->setConstructorArgs( array( $params ) )
			->getMockForAbstractClass();
		$provider->setLogger( new \TestLogger() );
		$provider->setConfig( $config );
		$provider->setManager( new SessionManager() );

		return $provider;
	}

	public function testConstructor() {
		$provider = $this->getMockBuilder( 'MediaWiki\\Session\\ImmutableSessionProviderWithCookie' )
			->getMockForAbstractClass();
		$priv = \TestingAccessWrapper::newFromObject( $provider );
		$this->assertNull( $priv->sessionCookieName );
		$this->assertSame( array(), $priv->sessionCookieOptions );

		$provider = $this->getMockBuilder( 'MediaWiki\\Session\\ImmutableSessionProviderWithCookie' )
			->setConstructorArgs( array( array(
				'sessionCookieName' => 'Foo',
				'sessionCookieOptions' => array( 'Bar' ),
			) ) )
			->getMockForAbstractClass();
		$priv = \TestingAccessWrapper::newFromObject( $provider );
		$this->assertSame( 'Foo', $priv->sessionCookieName );
		$this->assertSame( array( 'Bar' ), $priv->sessionCookieOptions );

		try {
			$provider = $this->getMockBuilder( 'MediaWiki\\Session\\ImmutableSessionProviderWithCookie' )
				->setConstructorArgs( array( array(
					'sessionCookieName' => false,
				) ) )
				->getMockForAbstractClass();
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'sessionCookieName must be a string',
				$ex->getMessage()
			);
		}

		try {
			$provider = $this->getMockBuilder( 'MediaWiki\\Session\\ImmutableSessionProviderWithCookie' )
				->setConstructorArgs( array( array(
					'sessionCookieOptions' => 'x',
				) ) )
				->getMockForAbstractClass();
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'sessionCookieOptions must be an array',
				$ex->getMessage()
			);
		}
	}

	public function testBasics() {
		$provider = $this->getProvider( null );
		$this->assertFalse( $provider->persistsSessionID() );
		$this->assertFalse( $provider->canChangeUser() );

		$provider = $this->getProvider( 'Foo' );
		$this->assertTrue( $provider->persistsSessionID() );
		$this->assertFalse( $provider->canChangeUser() );

		$msg = $provider->whyNoSession();
		$this->assertInstanceOf( 'Message', $msg );
		$this->assertSame( 'sessionprovider-nocookies', $msg->getKey() );
	}

	public function testGetVaryCookies() {
		$provider = $this->getProvider( null );
		$this->assertSame( array(), $provider->getVaryCookies() );

		$provider = $this->getProvider( 'Foo' );
		$this->assertSame( array( 'wgCookiePrefixFoo' ), $provider->getVaryCookies() );

		$provider = $this->getProvider( 'Foo', 'Bar' );
		$this->assertSame( array( 'BarFoo' ), $provider->getVaryCookies() );

		$provider = $this->getProvider( 'Foo', '' );
		$this->assertSame( array( 'Foo' ), $provider->getVaryCookies() );
	}

	public function testGetSessionIdFromCookie() {
		$this->setMwGlobals( 'wgCookiePrefix', 'wgCookiePrefix' );
		$request = new \FauxRequest();
		$request->setCookies( array(
			'' => 'empty---------------------------',
			'Foo' => 'foo-----------------------------',
			'wgCookiePrefixFoo' => 'wgfoo---------------------------',
			'BarFoo' => 'foobar--------------------------',
			'bad' => 'bad',
		), '' );

		$provider = \TestingAccessWrapper::newFromObject( $this->getProvider( null ) );
		try {
			$provider->getSessionIdFromCookie( $request );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \BadMethodCallException $ex ) {
			$this->assertSame(
				'MediaWiki\\Session\\ImmutableSessionProviderWithCookie::getSessionIdFromCookie ' .
					'may not be called when $this->sessionCookieName === null',
				$ex->getMessage()
			);
		}

		$provider = \TestingAccessWrapper::newFromObject( $this->getProvider( 'Foo' ) );
		$this->assertSame(
			'wgfoo---------------------------',
			$provider->getSessionIdFromCookie( $request )
		);

		$provider = \TestingAccessWrapper::newFromObject( $this->getProvider( 'Foo', 'Bar' ) );
		$this->assertSame(
			'foobar--------------------------',
			$provider->getSessionIdFromCookie( $request )
		);

		$provider = \TestingAccessWrapper::newFromObject( $this->getProvider( 'Foo', '' ) );
		$this->assertSame(
			'foo-----------------------------',
			$provider->getSessionIdFromCookie( $request )
		);

		$provider = \TestingAccessWrapper::newFromObject( $this->getProvider( 'bad', '' ) );
		$this->assertSame( null, $provider->getSessionIdFromCookie( $request ) );

		$provider = \TestingAccessWrapper::newFromObject( $this->getProvider( 'none', '' ) );
		$this->assertSame( null, $provider->getSessionIdFromCookie( $request ) );
	}

	protected function getSentRequest() {
		$sentResponse = $this->getMock( 'FauxResponse', array( 'headersSent', 'setCookie', 'header' ) );
		$sentResponse->expects( $this->any() )->method( 'headersSent' )
			->will( $this->returnValue( true ) );
		$sentResponse->expects( $this->never() )->method( 'setCookie' );
		$sentResponse->expects( $this->never() )->method( 'header' );

		$sentRequest = $this->getMock( 'FauxRequest', array( 'response' ) );
		$sentRequest->expects( $this->any() )->method( 'response' )
			->will( $this->returnValue( $sentResponse ) );
		return $sentRequest;
	}

	/**
	 * @dataProvider providePersistSession
	 * @param bool $secure
	 * @param bool $remember
	 */
	public function testPersistSession( $secure, $remember ) {
		$this->setMwGlobals( array(
			'wgCookieExpiration' => 100,
			'wgSecureLogin' => false,
		) );

		$provider = $this->getProvider( 'session' );
		$provider->setLogger( new \Psr\Log\NullLogger() );
		$priv = \TestingAccessWrapper::newFromObject( $provider );
		$priv->sessionCookieOptions = array(
			'prefix' => 'x',
			'path' => 'CookiePath',
			'domain' => 'CookieDomain',
			'secure' => false,
			'httpOnly' => true,
		);

		$sessionId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		$user = User::newFromName( 'UTSysop' );
		$this->assertFalse( $user->requiresHTTPS(), 'sanity check' );

		$backend = new SessionBackend(
			new SessionId( $sessionId ),
			new SessionInfo( SessionInfo::MIN_PRIORITY, array(
				'provider' => $provider,
				'id' => $sessionId,
				'persisted' => true,
				'userInfo' => UserInfo::newFromUser( $user, true ),
				'idIsSafe' => true,
			) ),
			new \EmptyBagOStuff(),
			new \EmptyBagOStuff(),
			new \Psr\Log\NullLogger(),
			10
		);
		\TestingAccessWrapper::newFromObject( $backend )->usePhpSessionHandling = false;
		$backend->setRememberUser( $remember );
		$backend->setForceHTTPS( $secure );

		// No cookie
		$priv->sessionCookieName = null;
		$request = new \FauxRequest();
		$provider->persistSession( $backend, $request );
		$this->assertSame( array(), $request->response()->getCookies() );

		// Cookie
		$priv->sessionCookieName = 'session';
		$request = new \FauxRequest();
		$time = time();
		$provider->persistSession( $backend, $request );

		$cookie = $request->response()->getCookieData( 'xsession' );
		$this->assertInternalType( 'array', $cookie );
		if ( isset( $cookie['expire'] ) && $cookie['expire'] > 0 ) {
			// Round expiry so we don't randomly fail if the seconds ticked during the test.
			$cookie['expire'] = round( $cookie['expire'] - $time, -2 );
		}
		$this->assertEquals( array(
			'value' => $sessionId,
			'expire' => null,
			'path' => 'CookiePath',
			'domain' => 'CookieDomain',
			'secure' => $secure,
			'httpOnly' => true,
			'raw' => false,
		), $cookie );

		$cookie = $request->response()->getCookieData( 'forceHTTPS' );
		if ( $secure ) {
			$this->assertInternalType( 'array', $cookie );
			if ( isset( $cookie['expire'] ) && $cookie['expire'] > 0 ) {
				// Round expiry so we don't randomly fail if the seconds ticked during the test.
				$cookie['expire'] = round( $cookie['expire'] - $time, -2 );
			}
			$this->assertEquals( array(
				'value' => 'true',
				'expire' => $remember ? 100 : null,
				'path' => 'CookiePath',
				'domain' => 'CookieDomain',
				'secure' => false,
				'httpOnly' => true,
				'raw' => false,
			), $cookie );
		} else {
			$this->assertNull( $cookie );
		}

		// Headers sent
		$request = $this->getSentRequest();
		$provider->persistSession( $backend, $request );
		$this->assertSame( array(), $request->response()->getCookies() );
	}

	public static function providePersistSession() {
		return array(
			array( false, false ),
			array( false, true ),
			array( true, false ),
			array( true, true ),
		);
	}

	public function testUnpersistSession() {
		$provider = $this->getProvider( 'session', '' );
		$provider->setLogger( new \Psr\Log\NullLogger() );
		$priv = \TestingAccessWrapper::newFromObject( $provider );

		// No cookie
		$priv->sessionCookieName = null;
		$request = new \FauxRequest();
		$provider->unpersistSession( $request );
		$this->assertSame( null, $request->response()->getCookie( 'session', '' ) );

		// Cookie
		$priv->sessionCookieName = 'session';
		$request = new \FauxRequest();
		$provider->unpersistSession( $request );
		$this->assertSame( '', $request->response()->getCookie( 'session', '' ) );

		// Headers sent
		$request = $this->getSentRequest();
		$provider->unpersistSession( $request );
		$this->assertSame( null, $request->response()->getCookie( 'session', '' ) );
	}

}
