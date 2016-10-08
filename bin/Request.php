<?php

/**
 * CLASE REQUEST.
 *
 * Almacena y devuelve diferentes variables
 * descriptivas de la petici�n request.
 *
 * @author Sergio P�rez <sergio.perez@albatronic.com>
 * @copyright INFORMATICA ALBATRONIC, SL
 * @version 1.0 22.05.2011
 */
class Request {

    /**
     * Par�metros que vienen por GET
     * @var array
     */
    private $parameters;

    /**
     * M�todo empleado en la peticion:
     * GET, POST, COOKIE
     * @var string
     */
    private $method;

    /**
     * Par�metros que viene por POST
     * @var array
     */
    private $request;

    /**
     * Ficheros
     * @var array
     */
    private $files;

    /**
     * Lenguaje aceptado
     * @var string
     */
    private $acceptLanguage;

    /**
     * Direccion IP del visitante
     * @var string
     */
    private $remoteAddr;

    /**
     * Url desde donde viene el visitante
     * @var string
     */
    private $httpReferer;

    /**
     * Navegador
     * @var string
     */
    private $userAgent;

    /**
     * Tipo de contenido
     * @var string
     */
    private $contentType;

    /**
     * Array con los nombre de los navegadores obsoletos
     * @var array
     */
    private $oldBrowsers = array(
        'MSIE 7',
        'MSIE 6',
    );

    public function __construct() {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->request = $_REQUEST;
        $this->files = $_FILES;
        $this->acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
        $this->remoteAddr = $_SERVER['REMOTE_ADDR'];
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'];
        $this->contentType = $_SERVER['CONTENT_TYPE'];
        $this->httpReferer = $_SERVER['HTTP_REFERER'];
    }

    /**
     * Analiza la Url amigable, la trozea y almacena
     * sus componentes en el array $parametros
     * @return array Parametros de la url
     */
    private function getUrl() {
        $parameters = array();
        $url = parse_url($_SERVER['REQUEST_URI']);
        foreach (explode("/", $url['path']) as $p) {
            if ($p != '') {
                $parameters[] = $p;
            }
        }

        return $parameters;
    }

    /**
     * Devuelve un array con los valores enviados por GET de la url amigable.
     *
     * IMPORTANTE:
     *   No se incluye en el array el path de la aplicaci�n, solo lo par�metros.
     *
     *   Ej: en el caso http://www.demo.com/app/Erp/Clientes/edit/1
     *
     *   Si el path de la aplicacion es "app/Erp", los parametros que se
     *   devuelven son:
     *   array('Clientes','edit','1')
     *
     * @param string $appPath path de la aplicacion
     * @return array Array con los parametros de la url
     */
    public function getParameters($appPath) {

        $this->parameters = $this->getRequest();

        // Cojo la url, incluido el path a la aplicacion
        $url = parse_url($_SERVER['SCRIPT_FILENAME'], PHP_URL_PATH);
        // A la url le quito la parte del path a la aplicacion
        $params = str_replace($appPath, "", $url);
        // Troceo los par�metros y los meto en un array.
        // El primer par�metro tendr� el �ndice 0 en el array
        foreach (explode("/", $params) as $p) {
            if ($p != '') {
                $this->parameters[] = $p;
            }
        }

        return $this->parameters;
    }

    /**
     * Devuelve un string con el m�todo (GET,POST,COOKIE)
     * @return string
     */
    public function getMethod() {
        return $this->method;
    }

    /**
     * Devuelve un array con las variables enviadas por POST
     * @return array
     */
    public function getRequest() {
        return $this->request;
    }

    /**
     * Devuelve array con los ficheros enviados por POST
     * @return array
     */
    public function getFiles() {
        return $this->files;
    }

    /**
     * Devuelve un string con el idioma aceptado por el cliente (ej: es-ES)
     * @return string
     */
    public function getLanguage() {
        return $this->acceptLanguage;
    }

    /**
     * Devuelve un string con el idiona aceptado por el cliente con 2 caracteres
     * @return string El lenguaje expresado en 2 caracteres
     */
    public function getShortLanguage() {
        return substr($this->acceptLanguage, 0, 2);
    }

    /**
     * Devuelve un string con la direcci�n IP del cliente
     * @return string
     */
    public function getRemoteAddr() {
        return $this->remoteAddr;
    }

    /**
     * Devuelve un string con el navegador utilizado por el cliente
     * @return string
     */
    public function getUserAgent() {
        return $this->userAgent;
    }

    /**
     * Devuelve un string con el tipo de contenido de la pagina
     * @return string
     */
    public function getContentType() {
        return $this->contentType;
    }

    /**
     * Devuelve true si el navegador es antiguo
     *
     *   Se consideran browsers antiguos los definidos en el array $this->oldBrowsers,
     *   actualmente son:
     *
     *   - MSIE 6
     *   - MSIE 7
     *
     * @return boolean
     */
    public function isOldBrowser() {

        $isOld = false;

        foreach ($this->oldBrowsers as $value) {
            $isOld = ( strpos($this->getUserAgent(), $value) != 0 );
            if ($isOld) {
                break;
            }
        }

        return $isOld;
    }

    /**
     * Devuelve TRUE / FALSE dependiendo si est�s en entorno
     * de desarrollo o de producci�n respectivamente.
     *
     * @return boolean TRUE si est�s en entorno desarrollo
     */
    public function isDevelopment() {
        return ( ($_SERVER['SERVER_NAME'] == 'localhost') or (substr($_SERVER['SERVER_NAME'], 0, 3) != 'www') );
    }

}

