<?php

/**
 * CONTROLADOR GENÉRICO
 *
 * Es extendido por todos los controladores y realiza las
 * acciones básicas de un CRUD
 *
 * @package Weblib
 * @author  Sergio Pérez <sperez@trevenque.es>
 * @date    20/3/16
 */
class Controller
{
    /**
     * El nombre del controlador. Con él se
     * identifica la ubicación del template a devolver
     * @var string
     */
    protected $controllerName;

    /**
     * Variables enviadas en el request por POST o por GET
     * @var array
     */
    protected $request;

    /**
     * Permisos de acceso definidos en config.yml
     * @var array
     */
    protected $profile;

    /**
     * Acciones permitidas
     * @var array
     */
    protected $permissions = array('acc', 'ins', 'del', 'upd', 'exp');

    /**
     * Valores a devolver al controlador principal para
     * que los renderice con el template correspondiente
     * @var array
     */
    protected $values;

    /**
     * Template de acceso no permitido
     * @var string
     */
    protected $templateForbiden = '../../smarty/templates/forbiden.html';


    public function __construct($request)
    {
        $this->controllerName = str_replace('Controller', '', get_class($this));

        if ($this->entity == '') {
            $this->entity = $this->controllerName;
        }

        $this->request = $request;

        // Cargo las eventuales variables de configuración globales y las del módulo
        // y las inyecto al array de values que se devuelve al template
        $this->values['config'] = $this->getConfig();

        // Si el módulo es multi-idioma, se pasa al template el código de idioma en curso
        if ($this->values['config']['multiLanguage']) {
            $this->values['codIdioma'] = ($this->request['codIdioma']) ? $this->request['codIdioma'] : ID_LANGUAGE_DB;
        }

        if (!$this->request['filter']['pagination']['orderBy']) {
            $this->request['filter']['pagination']['orderBy'] = $this->values['config']['orderBy'];
        }

        // Control de acceso al módulo
        $this->profile = $this->getProfile($this->values['config']['profiles']);
        $this->values['profile'] = $this->profile;

    }

    /**
     * Devuelve la home (index.html) del controller
     *
     * @return array
     */
    public function IndexAction()
    {
        $template = ($this->isAllow('acc')) ? $this->controllerName . "/index.html" : $this->templateForbiden;

        return array(
            'template' => $template,
            'values' => $this->values,
        );
    }

    /**
     * Genera una listado por pantalla en base al filtro
     * indicado en $this->request['filter']
     * Puede recibir un filtro adicional
     *
     * @param string $aditionalFilter
     * @return array con el template y valores a renderizar
     */
    public function listAction($aditionalFilter = '')
    {

        if ($this->isAllow('acc')) {
            $objeto = new $this->entity();

            $this->values['listado'] = $objeto->getList($this->request['filter']['keyWords'], $this->request['filter']['pagination'], $aditionalFilter);
            $this->values['objeto'] = $objeto;
            unset($obj);
            $template = $this->controllerName . '/list.html';
        } else {
            $template = $this->templateForbiden;
        }

        return array('template' => $template, 'values' => $this->values);
    }

    /**
     * Genera una listado por pantalla en base al filtro
     * indicado en $this->request['filter']
     *
     * Devuelve el template que permite edición en modo listado
     *
     * Puede recibir un filtro adicional
     *
     * @param string $aditionalFilter
     * @return array con el template y valores a renderizar
     */
    public function listFormAction($aditionalFilter = '')
    {
        if ($this->isAllow('acc')) {
            $objeto = new $this->entity();

            $this->values['listado'] = $objeto->getList($this->request['filter']['keyWords'], $this->request['filter']['pagination'], $aditionalFilter);

            // Añadir un elemento vacío al principio para utilizarlo como registro nuevo
            $nuevo = $this->values['listado']['data'][0];
            foreach ($nuevo as $key => $value) {
                $nuevo[$key] = '';
            }
            array_unshift($this->values['listado']['data'], $nuevo);

            $this->values['objeto'] = $objeto;

            $template = $this->controllerName . '/listForm.html';
        } else {
            $template = $this->templateForbiden;
        }

        return array('template' => $template, 'values' => $this->values);

    }

    /**
     * Edita, actualiza o borrar un registro
     *
     * Si viene por GET es editar
     * Si viene por POST puede ser actualizar o borrar
     * según el valor de $this->request['accion']
     *
     * @return array con el template y valores a renderizar
     */
    public function editAction()
    {
        switch ($this->request["METHOD"]) {

            case 'GET':
                //SI EN LA POSICION 3 DEL REQUEST VIENE ALGO,
                //SE ENTIENDE QUE ES EL VALOR DE LA CLAVE PARA LINKAR CON LA ENTIDAD PADRE
                //ESTO SE UTILIZA PARA LOS FORMULARIOS PADRE->HIJO
                if ($this->request['3'] != '')
                    $this->values['linkBy']['value'] = $this->request['3'];

                //MOSTRAR DATOS. El ID viene en la posicion 2 del request
                $datos = new $this->entity($this->request[$this->primaryKey]);

                if ($datos->isPersisted()) {
                    $this->values['errors'] = $datos->getErrors();
                } else {
                    $this->values['errors'] = array("Valor no encontrado. El objeto que busca no existe. Es posible que haya sido eliminado por otro usuario.");
                }

                $this->values['datos'] = $datos;
                $template = $this->controllerName . '/edit.html';

                return array('template' => $template, 'values' => $this->values);
                break;

            case 'POST':
                //COGER DEL REQUEST EL LINK A LA ENTIDAD PADRE
                if ($this->values['linkBy']['id'] != '') {
                    $this->values['linkBy']['value'] = $this->request[$this->controllerName][$this->values['linkBy']['id']];
                }

                switch ($this->request['accion']) {
                    case 'Guardar': //GUARDAR DATOS

                        if ($this->isAllow('upd')) {
                            // Cargo la entidad
                            $datos = new $this->entity($this->request[$this->controllerName][$this->primaryKey]);
                            // Vuelco los datos del request
                            $datos->bind($this->request[$this->controllerName]);

                            if ($datos->validate()) {
                                $this->values['alerts'] = $datos->getAlerts();
                                if (!$datos->update()) {
                                    $this->values['errors'] = $datos->getErrors();
                                }

                                //Recargo el objeto para refrescar las propiedas que
                                //hayan podido ser objeto de algun calculo durante el proceso de guardado.
                                $datos = new $this->entity($this->request[$this->controllerName][$datos->getPrimaryKeyName()]);
                            } else {
                                $this->values['errors'] = $datos->getErrors();
                                $this->values['alerts'] = $datos->getAlerts();
                            }
                            $this->values['datos'] = $datos;
                            $template = $this->controllerName . '/edit.html';
                        } else {
                            $template = $this->templateForbiden;
                        }

                        return array('template' => $template, 'values' => $this->values);
                        break;

                    case 'Borrar':

                        if ($this->isAllow('del')) {
                            $datos = new $this->entity($this->request[$this->controllerName][$this->primaryKey]);
                            if ($datos->delete()) {
                                $datos = new $this->entity();
                                $this->values['datos'] = $datos;
                                $this->values['errors'] = array();
                                $template = $this->entity . '/new.html';
                            } else {
                                $this->values['datos'] = $datos;
                                $this->values['errors'] = $datos->getErrors();
                                $this->values['alerts'] = $datos->getAlerts();
                                $template = $this->controllerName . '/edit.html';
                            }
                            unset($datos);
                        } else {
                            $template = $this->templateForbiden;
                        }
                        return array('template' => $template, 'values' => $this->values);
                        break;
                }
                break;
        }
    }

    /**
     * Actualiza o borrar un registro
     *
     * Devuelve el template listFrom, se utiliza para
     * editar registros en formato listado.
     *
     * @return array con el template y valores a renderizar
     */
    public function editListAction()
    {

        //COGER DEL REQUEST EL LINK A LA ENTIDAD PADRE
        if ($this->values['linkBy']['id'] != '') {
            $this->values['linkBy']['value'] = $this->request[$this->controllerName][$this->values['linkBy']['id']];
        }

        switch ($this->request['accion']) {
            case 'Guardar':
                if ($this->isAllow('upd')) {
                    // Cargo la entidad
                    $datos = new $this->entity($this->request[$this->controllerName][$this->primaryKey]);
                    // Vuelco los datos del request
                    $datos->bind($this->request[$this->controllerName]);

                    if ($datos->validate()) {
                        $this->values['alerts'] = $datos->getAlerts();
                        if (!$datos->update()) {
                            $this->values['errors'] = $datos->getErrors();
                        }
                    } else {
                        $this->values['errors'] = $datos->getErrors();
                        $this->values['alerts'] = $datos->getAlerts();
                    }
                }
                break;

            case 'Borrar':
                if ($this->isAllow('del')) {
                    $datos = new $this->entity($this->request[$this->controllerName][$this->primaryKey]);
                    $datos->delete();
                    $this->values['errors'] = $datos->getErrors();
                    $this->values['alerts'] = $datos->getAlerts();
                    unset($datos);
                }
                break;
        }
        return $this->listFormAction();
    }

    /**
     * Elimina un registro entrando por GET
     *
     * @return array con el template y valores a renderizar
     */
    public function deleteAction()
    {

        if ($this->isAllow('del')) {
            $datos = new $this->entity();
            $primaryKeyName = $datos->getPrimaryKeyName();
            $datos = new $this->entity($this->request[$primaryKeyName]);

            if ($datos->delete()) {
                $datos = new $this->entity();
                $this->values['datos'] = $datos;
                $this->values['errors'] = array();
                $template = $this->controllerName . '/new.html';
            } else {
                $this->values['datos'] = $datos;
                $this->values['errors'] = $datos->getErrors();
                $this->values['alerts'] = $datos->getAlerts();
                $template = $this->controllerName . 'edit.html';
            }
            unset($datos);

        } else {
            $template = $this->templateForbiden;
        }

        return array('template' => $template, 'values' => $this->values);
    }

    /**
     * Crea un registro nuevo
     *
     * Si viene por GET muestra un template vacio
     * Si viene por POST crea un registro
     *
     * @return array con el template y valores a renderizar
     */
    public function newAction()
    {
        if ($this->isAllow('ins')) {
            switch ($this->request["METHOD"]) {
                case 'GET': //MOSTRAR FORMULARIO VACIO
                    //SI EN LA POSICION 2 DEL REQUEST VIENE ALGO,
                    //SE ENTIENDE QUE ES EL VALOR DE LA CLAVE PARA LINKAR CON LA ENTIDAD PADRE
                    //ESTO SE UTILIZA PARA LOS FORMULARIOS PADRE->HIJO
                    if ($this->request['2'] != '')
                        $this->values['linkBy']['value'] = $this->request['2'];

                    $this->values['datos'] = new $this->entity(null, $this->values['config']['defaultValues']);
                    $this->values['errors'] = array();
                    $template = $this->controllerName . '/new.html';
                    break;

                case 'POST':
                    //CREAR NUEVO REGISTRO
                    //COGER EL LINK A LA ENTIDAD PADRE
                    if ($this->values['linkBy']['id'] != '') {
                        $this->values['linkBy']['value'] = $this->request[$this->controllerName][$this->values['linkBy']['id']];
                    }

                    $datos = new $this->entity();
                    $datos->bind($this->request[$this->controllerName]);

                    if ($datos->validate()) {
                        $lastId = $datos->insert();

                        $this->values['errors'] = $datos->getErrors();
                        $this->values['alerts'] = $datos->getAlerts();

                        //Recargo el objeto para refrescar las propiedades que
                        //hayan podido ser objeto de algun calculo durante el proceso
                        //de guardado
                        if ($lastId) {
                            $datos = new $this->entity($lastId);
                        }
                        $this->values['datos'] = $datos;
                        $template = $this->controllerName . '/edit.html';
                    } else {
                        $this->values['datos'] = $datos;
                        $this->values['errors'] = $datos->getErrors();
                        $this->values['alerts'] = $datos->getAlerts();
                        $template = $this->controllerName . '/new.html';
                    }
                    break;
            }
        } else {
            $template = $this->templateForbiden;
        }

        return array('template' => $template, 'values' => $this->values);
    }

    /**
     * Crea un registro nuevo
     *
     * Devuelve el template listFrom, se utiliza para
     * crear registros en formato listado.
     *
     * @return array con el template y valores a renderizar
     */
    public function newListAction()
    {
        if ($this->isAllow('ins')) {
            //COGER EL LINK A LA ENTIDAD PADRE
            if ($this->values['linkBy']['id'] != '') {
                $this->values['linkBy']['value'] = $this->request[$this->controllerName][$this->values['linkBy']['id']];
            }

            $datos = new $this->entity();
            $datos->bind($this->request[$this->controllerName]);

            if ($datos->validate()) {
                $datos->insert();
            }

            $this->values['errors'] = $datos->getErrors();
            $this->values['alerts'] = $datos->getAlerts();
        } else {
            $template = $this->templateForbiden;
        }
        return $this->listFormAction();
    }

    /**
     * Devuelve la ayuda del controlador en curso
     *
     * @return array con el template y valores a renderizar
     */
    public function helpAction()
    {
        $obj = new Help();
        $this->values['data'] = $obj->getBy('controller', $this->controllerName);

        return array(
            'template' => $this->controllerName . '/help.html',
            'values' => $this->values,
        );
    }

    /**
     * Redirige al método y controller indicado
     *
     * @param string $controller El nombre del controller
     * @param string $action El nombre del método. Por defecto el Index
     * @return array
     */
    protected function redirect($controller, $action = "Index")
    {
        $controlador = "{$controller}Controller";
        $metodo = "{$action}Action";
        $fileController = "{$controller}/{$controller}Controller.php";
        if (!file_exists($fileController)) {
            $controlador = "IndexController";
            $metodo = "IndexAction";
            $fileController = "Index/IndexController.php";
        }

        include_once($fileController);
        $controller = new $controlador($this->request);
        return $controller->{$metodo}();
    }


    /**
     * Devuelve array con los permisos de acceso al módulo
     * en base al perfil del usuario en curso $_SESSION['CUP_PROFILE']
     *
     * @param $profiles
     * @return array
     */
    protected function getProfile($profiles)
    {

        if (count($profiles) == 0) {
            // No se han definido perfiles de acceso, se entienden
            // que todos los perfiles de usuario pueden acceder.
            foreach ($this->permissions as $permission) {
                $profile[$permission] = true;
            }
        } else {
            foreach ($this->permissions as $permission) {
                $profile[$permission] = isset($profiles[$_SESSION['CUP_PROFILE']][$permission]) ?
                    $profiles[$_SESSION['CUP_PROFILE']][$permission] :
                    $profiles[$_SESSION['CUP_PROFILE']]['*'];
            }
        }
        return $profile;
    }

    /**
     * Devuelve array con las variables del configuración
     * del controlador en curso. Haciendo merge con las globales
     * del proyecto y prevaleciendo las del controlador.
     *
     * @return array
     */
    protected function getConfig()
    {

        $yaml = sfYaml::load(PATH . 'config.yml');
        $configGlobal = is_array($yaml) ? $yaml['config'] : array();

        $yaml = sfYaml::load('config.yml');
        $configController = is_array($yaml) ? $yaml['config'] : array();

        // Las variables del controller prevalecen sobre las globales
        return array_merge($configGlobal, $configController);
    }

    /**
     * Devuelve true/false en función si el perfil del usuario
     * en curso puede realizar la acción indicada en $action
     *
     * @param string $action La acción a realizar (acc,ins,del,upd,exp,...)
     * @return bool
     */
    protected function isAllow($action)
    {

        return ($this->profile[$action] || (!isset($this->profile[$action]) && $this->profile['*']));
    }
}