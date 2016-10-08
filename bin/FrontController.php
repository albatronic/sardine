<?php
/**
 * CONTROLADOR FRONTAL PARA TODAS LAS PETICIONES
 *
 * ANALIZA LA URL, IDENTIFICA EL CONTROLADOR Y LA ACCIÓN REQUERIDA
 *
 * EJECUTA LA ACCIÓN“N Y RENDERIZA EL TEMPLATE INDICADO CON LOS DATOS DEVUELTOS
 *
 *
 * User: Sergio Pérez <sperez@trevenque.es>
 * Date: 20/3/16
 * Time: 22:45
 */

include("../../../clases/configuracion.php");
include("../../init.php");
include_once(PATH_CLASS . "SmartyTemplate3.php");

if ($_SESSION["CUP_CODE"] == "") {
    header("Location: ../../index.php");
    exit;
}

include("autoloader.inc.php");

//-----------------------------------------------------------------
// INSTANCIAR UN OBJETO DE LA CLASE REQUEST PARA TENER DISPONIBLES
// TODOS LOS VALORES QUE CONSTITUYEN LA PETICION E IDENTIFICAR
// SI LA PETICION ES 'GET' O 'POST', ASI COMO EL CONTROLADOR Y
// ACCION SOLICITADA.
//-----------------------------------------------------------------
$rq = new Request();

switch ($rq->getMethod()) {
    case 'GET':
        $request = $rq->getParameters(PATH . 'modules/');
        $request['METHOD'] = "GET";
        $controller = ucfirst($request[0]);
        $action = (isset($request['action'])) ? $request['action'] : "";
        break;

    case 'POST':
        $request = $rq->getRequest();
        $request['METHOD'] = "POST";
        $controller = ucfirst($request['controller']);
        $action = $request['action'];
        break;
}

// Validar que el controlador requerido exista.
// En caso contrario fuerzo el controlador Index
$fileController = PATH . "modules/" . $controller . "/" . $controller . "Controller.php";
if (!file_exists($fileController)) {
    $controller = "Index";
    $action = "Index";
    $fileController = PATH . "modules/Index/IndexController.php";
}

$clase = $controller . "Controller";
$metodo = $action . "Action";

//---------------------------------------------------------------
// INSTANCIAR EL CONTROLLER REQUERIDO
// SI EL METODO SOLICITADO EXISTE, LO EJECUTO, SI NO EJECUTO EL METODO INDEX
// RENDERIZAR EL RESULTADO CON EL TEMPLATE Y DATOS DEVUELTOS
// SI NO EXISTE EL TEMPLATE DEVUELTO, MUESTRO UNA PAGINA DE ERROR
//---------------------------------------------------------------
include_once $fileController;
$objController = new $clase($request);
if (!method_exists($objController, $metodo)) {
    $metodo = "IndexAction";
}
$result = $objController->{$metodo}();
unset($objController);
// -----------------------------------------------------------------


// Si no se ha indicado ningún layout específico, se utiliza el de por defecto.
// NOTA: Cualquier controlador puede seleccionar un layout diferente.
if ($result['templateLayout'] == '') {
    $result['templateLayout'] = 'layout.html';
}
$result['templateLayout'] = PATH . 'smarty/templates/' . $result['templateLayout'];
// -----------------------------------------------------------------


// Si está activado el modo debuger se envía al smarty los valores para debugear
// La activación/desactivación se puede hacer a nivel global o a nivel de
// cada controlador, prevaleciendo el valor del controlador.
if ($result['values']['config']['debugMode']) {
  $debugValues = array(
      'controller' => $controller,
      'action' => $metodo,
      'template' => $result['template'],
      'templateLayout' => $result['templateLayout'],
      'values' => $result['values'],      
  );
}
// -----------------------------------------------------------------

$smarty = new SmartyTemplate();
include(PATH."header.php");

DEFINE("PATH_SMARTY", PATH . 'smarty/templates/');

$smarty->template_dir = PATH . "modules/";
$smarty->assign("values", $result['values']);
$smarty->assign("template", $result['template']);
$smarty->assign("templateLayout", $result['templateLayout']);
$smarty->assign("controller", $controller);
$smarty->assign("debugValues", print_r($debugValues,true));
$smarty->assign("PATH_SMARTY", PATH_SMARTY);
$smarty->assign("PATH_APP", PATH);
$smarty->assign("URL_CUP", 'http://' . $_SERVER['HTTP_HOST'] . '/es/cup');
$smarty->display($result['template']);

