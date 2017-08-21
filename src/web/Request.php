<?php
/**
 * Author: lf
 * Blog: https://blog.feehi.com
 * Email: job@feehi.com
 * Created at: 2017-08-19 11:03
 */

namespace feehi\web;

use yii;
use yii\base\InvalidConfigException;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\NotFoundHttpException;
use yii\web\RequestParserInterface;

class Request extends \yii\web\Request
{

    /* @var $swooleRequest \swoole_http_request */
    public $swooleRequest;

    public $enableCsrfValidation = true;

    /**
     * @var CookieCollection Collection of request cookies.
     */
    private $_cookies;
    /**
     * @var HeaderCollection Collection of request headers.
     */
    private $_headers;


    /**
     * Resolves the current request into a route and the associated parameters.
     * @return array the first element is the route, and the second is the associated parameters.
     * @throws NotFoundHttpException if the request cannot be resolved.
     */
    public function resolve()
    {
        $result = Yii::$app->getUrlManager()->parseRequest($this);
        if ($result !== false) {
            list ($route, $params) = $result;
            if ($this->_queryParams === null) {
                $this->setQueryParams( array_merge($params, $this->getQueryParams()) ); // preserve numeric keys
            } else {
                $this->_queryParams = $params + $this->_queryParams;
            }
            return [$route, $this->getQueryParams()];
        }

        throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
    }

    public function getHeaders()
    {
        if ($this->_headers === null) {
            $this->_headers = new HeaderCollection;
            $headers = $this->swooleRequest->header;
            foreach ($headers as $name => $value) {
                $this->_headers->add($name, $value);
            }
        }
        return $this->_headers;
    }

    public function getMethod()
    {
        return $this->swooleRequest->server["request_method"];
    }

    public function getIsAjax()
    {
        return isset($this->swooleRequest->header["x-requested-with"]) && $this->swooleRequest->header["x-requested-with"] === 'XMLHttpRequest';
    }

    //待带修改
    public function getIsPjax()
    {
        return $this->getIsAjax() && !empty($this->swooleRequest->header["x-requested-with"]);
    }

    public function getIsFlash()
    {
        return isset($this->swooleRequest->header["user-agent"]) &&
            (stripos($this->swooleRequest->header["user-agent"], 'Shockwave') !== false || stripos($this->swooleRequest->header["user-agent"], 'Flash') !== false);
    }

    private $_rawBody;

    public function getRawBody()
    {
        if ($this->_rawBody === null) {
            $this->_rawBody = file_get_contents("php://input");
            return $this->_rawBody;
        }

        return $this->_rawBody;
    }

    private $_bodyParams;

    public function getBodyParams()
    {
        if ($this->_bodyParams === null) {
            if (isset($this->swooleRequest->post[$this->methodParam])) {
                $this->_bodyParams = $this->swooleRequest->post;
                unset($this->_bodyParams[$this->methodParam]);
                return $this->_bodyParams;
            }

            $rawContentType = $this->getContentType();
            if (($pos = strpos($rawContentType, ';')) !== false) {
                // e.g. application/json; charset=UTF-8
                $contentType = substr($rawContentType, 0, $pos);
            } else {
                $contentType = $rawContentType;
            }

            if (isset($this->parsers[$contentType])) {
                $parser = Yii::createObject($this->parsers[$contentType]);
                if (!($parser instanceof RequestParserInterface)) {
                    throw new InvalidConfigException("The '$contentType' request parser is invalid. It must implement the yii\\web\\RequestParserInterface.");
                }
                $this->_bodyParams = $parser->parse($this->getRawBody(), $rawContentType);
            } elseif (isset($this->parsers['*'])) {
                $parser = Yii::createObject($this->parsers['*']);
                if (!($parser instanceof RequestParserInterface)) {
                    throw new InvalidConfigException("The fallback request parser is invalid. It must implement the yii\\web\\RequestParserInterface.");
                }
                $this->_bodyParams = $parser->parse($this->getRawBody(), $rawContentType);
            } elseif ($this->getMethod() === 'POST') {
                // PHP has already parsed the body so we have all params in $_POST
                $this->_bodyParams = $this->swooleRequest->post;
            } else {
                $this->_bodyParams = [];
                mb_parse_str($this->getRawBody(), $this->_bodyParams);
            }
        }

        return $this->_bodyParams;
    }

    private $_queryParams;

    public function getQueryParams()
    {
        if ($this->_queryParams === null) {
            $get = [];
            if( isset( $this->swooleRequest->server['query_string'] )){
                $temp = explode('&', urldecode( $this->swooleRequest->server['query_string'] ) );
                foreach ($temp as $v){
                    $arr = explode('=', $v);
                    if( count($arr) < 2 ) continue;
                    $get[$arr[0]] = $arr[1];
                }
            };
            return $get;
        }

        return $this->_queryParams;
    }

    public function setQueryParams($values)
    {
        $this->_queryParams = $values;
    }

    private $_hostInfo;
    private $_hostName;


    public function getHostInfo()
    {
        if ($this->_hostInfo === null) {
            $this->_hostInfo = $this->swooleRequest->header['host'];
        }

        return $this->_hostInfo;
    }

    public function setHostInfo($value)
    {
        $this->_hostName = null;
        $this->_hostInfo = $value === null ? null : rtrim($value, '/');
    }

    public function getHostName()
    {
        if ($this->_hostName === null) {
            $this->_hostName = parse_url($this->getHostInfo(), PHP_URL_HOST);
        }

        return $this->_hostName;
    }

    private $_baseUrl;

    public function getBaseUrl()
    {
        if ($this->_baseUrl === null) {
            $this->_baseUrl = '';
        }

        return $this->_baseUrl;
    }

    public function setBaseUrl($value)
    {
        $this->_baseUrl = $value;
    }

    private $_scriptUrl;

    public function getScriptUrl()
    {
        if ($this->_scriptUrl === null) {
            $this->scriptUrl = '/';
        }

        return $this->_scriptUrl;
    }

    public function setScriptUrl($value)
    {
        $this->_scriptUrl = $value === null ? null : '/' . trim($value, '/');
    }

    private $_scriptFile;


    public function getScriptFile()
    {
        if (isset($this->_scriptFile)) {
            return $this->_scriptFile;
        }

        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            return $_SERVER['SCRIPT_FILENAME'];
        }

        throw new InvalidConfigException('Unable to determine the entry script file path.');
    }

    public function setScriptFile($value)
    {
        $this->_scriptFile = $value;
    }

    private $_pathInfo;

    public function getPathInfo()
    {
        if ($this->_pathInfo === null) {
            $this->_pathInfo = $this->resolvePathInfo();
        }

        return $this->_pathInfo;
    }

    public function setPathInfo($value)
    {
        $this->_pathInfo = $value === null ? null : ltrim($value, '/');
    }

    protected function resolvePathInfo()
    {
        $pathInfo = $this->getUrl();

        if (($pos = strpos($pathInfo, '?')) !== false) {
            $pathInfo = substr($pathInfo, 0, $pos);
        }

        $pathInfo = urldecode($pathInfo);

        // try to encode in UTF8 if not so
        // http://w3.org/International/questions/qa-forms-utf-8.html
        if (!preg_match('%^(?:
            [\x09\x0A\x0D\x20-\x7E]              # ASCII
            | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
            | \xE0[\xA0-\xBF][\x80-\xBF]         # excluding overlongs
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
            | \xED[\x80-\x9F][\x80-\xBF]         # excluding surrogates
            | \xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
            | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
            | \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
            )*$%xs', $pathInfo)
        ) {
            $pathInfo = utf8_encode($pathInfo);
        }

        $pathInfo = substr($pathInfo, 1);

        if (substr($pathInfo, 0, 1) === '/') {
            $pathInfo = substr($pathInfo, 1);
        }

        return (string) $pathInfo;
    }

    public function getAbsoluteUrl()
    {
        return $this->getHostInfo() . $this->getUrl();
    }

    private $_url;

    public function getUrl()
    {
        if ($this->_url === null) {
            $this->_url = $this->resolveRequestUri();
        }

        return $this->_url;
    }

    public function setUrl($value)
    {
        $this->_url = $value;
    }

    protected function resolveRequestUri()
    {
        $requestUri = $this->swooleRequest->server['request_uri'];
        if( isset($this->swooleRequest->server['query_string']) ) $requestUri .= '?' . $this->swooleRequest->server['query_string'];
        return $requestUri;
    }

    public function getQueryString()
    {
        return isset($this->swooleRequest->server['query_string']) ? $this->swooleRequest->server['query_string'] : '';
    }

    public function getServerName()
    {
        return $this->swooleRequest->server['server_software'];
    }

    public function getServerPort()
    {
        return $this->swooleRequest->server['server_port'];
    }

    public function getReferrer()
    {
        return $this->swooleRequest->header["referer"];
    }

    public function getUserAgent()
    {
        return $this->swooleRequest->header['user-agent'];
    }

    public function getUserIP()
    {
        return $this->swooleRequest->server['remote_addr'];
    }

    private $_port;

    public function getPort()
    {
        return $this->swooleRequest->server['server_port'];
    }

    public function setPort($value)
    {
        if ($value != $this->_port) {
            $this->_port = (int) $value;
            $this->_hostInfo = null;
        }
    }

    private $_securePort;

    public function getSecurePort()
    {
        if ($this->_securePort === null) {
            $this->_securePort = $this->getIsSecureConnection() && isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 443;
        }

        return $this->_securePort;
    }

    public function setSecurePort($value)
    {
        if ($value != $this->_securePort) {
            $this->_securePort = (int) $value;
            $this->_hostInfo = null;
        }
    }

    private $_contentTypes;

    public function getAcceptableContentTypes()
    {
        if ($this->_contentTypes === null) {
            if (isset($this->swooleRequest->header["accept"])) {
                $this->_contentTypes = $this->parseAcceptHeader($this->swooleRequest->header["accept"]);
            } else {
                $this->_contentTypes = [];
            }
        }

        return $this->_contentTypes;
    }

    public function setAcceptableContentTypes($value)
    {
        $this->_contentTypes = $value;
    }

    public function getContentType()
    {
        if (isset($this->swooleRequest->header['content-type'])) {
            return $this->swooleRequest->header['content-type'];
        }

        return null;
    }

    private $_languages;

    public function getAcceptableLanguages()
    {
        if ($this->_languages === null) {
            if (isset($this->swooleRequest->header['accept-language'])) {
                $this->_languages = array_keys($this->parseAcceptHeader($this->swooleRequest->header['accept-language']));
            } else {
                $this->_languages = [];
            }
        }

        return $this->_languages;
    }

    public function setAcceptableLanguages($value)
    {
        $this->_languages = $value;
    }

    public function getPreferredLanguage(array $languages = [])
    {
        if (empty($languages)) {
            return Yii::$app->language;
        }
        foreach ($this->getAcceptableLanguages() as $acceptableLanguage) {
            $acceptableLanguage = str_replace('_', '-', strtolower($acceptableLanguage));
            foreach ($languages as $language) {
                $normalizedLanguage = str_replace('_', '-', strtolower($language));

                if ($normalizedLanguage === $acceptableLanguage || // en-us==en-us
                    strpos($acceptableLanguage, $normalizedLanguage . '-') === 0 || // en==en-us
                    strpos($normalizedLanguage, $acceptableLanguage . '-') === 0) { // en-us==en

                    return $language;
                }
            }
        }

        return reset($languages);
    }

    /**
     * Gets the Etags.
     *
     * @return array The entity tags
     */
    public function getETags()
    {
        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            return preg_split('/[\s,]+/', str_replace('-gzip', '', $_SERVER['HTTP_IF_NONE_MATCH']), -1, PREG_SPLIT_NO_EMPTY);
        }

        return [];
    }

    public function getCookies()
    {
        if ($this->_cookies === null) {
            $this->_cookies = new CookieCollection($this->loadCookies(), [
                'readOnly' => true,
            ]);
        }

        return $this->_cookies;
    }

    protected function loadCookies()
    {
        $cookies = [];
        if ($this->enableCookieValidation) {
            if ($this->cookieValidationKey == '') {
                throw new InvalidConfigException(get_class($this) . '::cookieValidationKey must be configured with a secret key.');
            }
            if( !isset($this->swooleRequest->cookie) ) return [];
            foreach ($this->swooleRequest->cookie as $name => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $data = Yii::$app->getSecurity()->validateData($value, $this->cookieValidationKey);
                if ($data === false) {
                    continue;
                }
                $data = @unserialize($data);
                if (is_array($data) && isset($data[0], $data[1]) && $data[0] === $name) {
                    $cookies[$name] = new Cookie([
                        'name' => $name,
                        'value' => $data[1],
                        'expire' => null,
                    ]);
                }
            }
        } else {
            foreach ($this->swooleRequest->cookie as $name => $value) {
                $cookies[$name] = new Cookie([
                    'name' => $name,
                    'value' => $value,
                    'expire' => null,
                ]);
            }
        }

        return $cookies;
    }

    private $_csrfToken;

    public function getCsrfToken($regenerate = false)
    {
        if ($this->_csrfToken === null || $regenerate) {
            if ($regenerate || ($token = $this->loadCsrfToken()) === null) {
                $token = $this->generateCsrfToken();
            }
            $this->_csrfToken = Yii::$app->security->maskToken($token);
        }

        return $this->_csrfToken;
    }

    protected function loadCsrfToken()
    {
        if ($this->enableCsrfCookie) {
            return $this->getCookies()->getValue($this->csrfParam);
        }
        return Yii::$app->getSession()->get($this->csrfParam);
    }

    protected function generateCsrfToken()
    {
        $token = Yii::$app->getSecurity()->generateRandomKey();
        if ($this->enableCsrfCookie) {
            $cookie = $this->createCsrfCookie($token);
            Yii::$app->getResponse()->getCookies()->add($cookie);
        } else {
            Yii::$app->getSession()->set($this->csrfParam, $token);
        }
        return $token;
    }

    public function getCsrfTokenFromHeader()
    {
        return $this->headers->get(static::CSRF_HEADER);
    }

    protected function createCsrfCookie($token)
    {
        $options = $this->csrfCookie;
        $options['name'] = $this->csrfParam;
        $options['value'] = $token;
        return new Cookie($options);
    }

    public function validateCsrfToken($clientSuppliedToken = null)
    {
        $method = $this->getMethod();
        // only validate CSRF token on non-"safe" methods http://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html#sec9.1.1
        if (!$this->enableCsrfValidation || in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $trueToken = $this->getCsrfToken();

        if ($clientSuppliedToken !== null) {
            return $this->validateCsrfTokenInternal($clientSuppliedToken, $trueToken);
        }

        return $this->validateCsrfTokenInternal($this->getBodyParam($this->csrfParam), $trueToken)
            || $this->validateCsrfTokenInternal($this->getCsrfTokenFromHeader(), $trueToken);
    }

    private function validateCsrfTokenInternal($clientSuppliedToken, $trueToken)
    {
        if (!is_string($clientSuppliedToken)) {
            return false;
        }

        $security = Yii::$app->security;

        return $security->unmaskToken($clientSuppliedToken) === $security->unmaskToken($trueToken);
    }
}