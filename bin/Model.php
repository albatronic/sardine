<?php

/**
 *
 * Clase para gestionar entidades de datos
 *
 * @package Weblib
 * @author Sergio Pérez <sperez@trevenque.es>
 * @date 23-03-2016
 *
 */
class Model
{
    /**
     * Ruta al archivo para cachear el modelo de datos
     * Por defecto: modules/_cache/cacheDescriptor.txt
     *
     * @var string
     */
    protected $_cacheFile = 'cup/modules/_cache/cacheDescriptor.txt';

    /**
     * Indica si los valores existentes en las propiedades
     * del objeto está persistidos o no en la base de datos
     * @var bool
     */
    protected $_persisted = false;

    /**
     * Array de mensajes de error
     * @var array
     */
    protected $_errors;
    /**
     * Array de mensajes de alerta (warnings)
     * @var array
     */
    protected $_alerts;

    /**
     * Array con el descriptor de la tabla asociada al objeto en curso
     * @var array
     */
    protected static $_tablesDescriptor = NULL;

    /**
     * Model constructor.
     *
     * Si se indica $primaryKeyValue se carga el objeto.
     *
     * Si no se indica $primaryKeyValue, las propiedades se llenarán con los valores por
     * defecto según el descriptor de la tabla y con los eventuales valores indicados
     * en $defaultValues, prevaleciendo estos sobre los primeros.
     *
     * @param string $primaryKeyValue El valor de la primarykey. Opcional
     * @param array $defaultValues Array con los valores por defecto para cada columna. Opcional
     */
    public function __construct($primaryKeyValue = '', $defaultValues = array())
    {
        $this->_cacheFile = PATH_WEB_ABS . $this->_cacheFile;

        if (!self::$_tablesDescriptor[$this->_table]) {
            self::$_tablesDescriptor[$this->_table] = $this->cacheaTableDescriptor();
        }

        $this->setPrimaryKeyValue($primaryKeyValue);

        $ok = ($primaryKeyValue) ? $this->load() : false;

        if (!$ok) {
            $this->setDefaultValues($defaultValues);
        }
    }

    /**
     *
     * Establece los valores por defecto de cada columna
     *
     * Si se indican $defaultValues prevalecen estos sobre
     * los del descriptor de la tabla
     *
     * @param array $defaultValues Valores por defecto para cada columna. Opcional
     * @return void
     */
    private function setDefaultValues($defaultValues = array())
    {
        foreach (self::$_tablesDescriptor[$this->_table]['columns'] as $column => $properties) {
            if ($column != $this->_primaryKey) {
                $this->$column = (isset($defaultValues[$column])) ? $defaultValues[$column] : $properties['Default'];
            }
        }
    }

    /**
     *
     * Genera listado en base al filtro y los parámetros de paginación
     *
     * @param string $keyWords El texto a buscar
     * @param array $pagination Array con los datos de la paginación (page, recordsPerPage, orderBy)
     * @param string $aditionalFilter Filtro adicional. Expresión booleana tipo: columna (=, <>, like, ...) valor
     * @return array Array con el resultado. keyWords => el texto buscado, data => array de registros obtenidos, pagination => parámetros de paginación
     */
    public function getList($keyWords = '', $pagination = array(), $aditionalFilter = '')
    {
        // Validaciones array pagination
        if (!$pagination['page']) {
            $pagination['page'] = 1;
        }
        if (!$pagination['recordsPerPage']) {
            $pagination['recordsPerPage'] = 15;
        }
        if (!$pagination['orderBy']) {
            $pagination['orderBy'] = "p.{$this->_primaryKey} ASC";
        }

        $filter = "(1)";

        if ($keyWords != '') {
            $columnas = array();
            if (count($this->_columnasBusqueda)) {
                // Valido que las columnas de busqueda indicadas en el modelo existan
                foreach ($this->_columnasBusqueda as $columna) {
                    if (isset(self::$_tablesDescriptor[$this->_table]['columns'][$columna])) {
                        $columnas[] = $columna;
                    }
                }
            } else {
                $columnas = $this->getColumnsNames();
            }
            if (count($columnas)) {
                $filter .= " AND ( ";
                foreach ($columnas as $columna) {
                    $filter .= "({$columna} LIKE '%{$keyWords}%') OR ";
                }
                $filter = substr($filter, 0, -4);
                $filter .= " )";
            }
        }

        if ($aditionalFilter != '') {
            $filter .= " AND ({$aditionalFilter})";
        }

        $query = "SELECT p.{$this->_primaryKey} {$this->_fromWhere} {$filter} {$this->_groupBy}";
        //echo $query;exit;
        $result = DB::getInstance()->select($query);
        $records = count($result);
        $pages = floor($records / $pagination['recordsPerPage']);
        if (($records % $pagination['recordsPerPage']) > 0) {
            $pages++;
        }
        $offset = ($pagination['page'] - 1) * $pagination['recordsPerPage'];

        $query = "SELECT {$this->_columnasDevolver}
                {$this->_fromWhere} {$filter}
                {$this->_groupBy}
                ORDER BY {$pagination['orderBy']}
                LIMIT {$offset},{$pagination['recordsPerPage']}";
        //echo $query;exit;
        $result = DB::getInstance()->select($query);

        return array(
            'primaryKeyName' => $this->getPrimaryKeyName(),
            'keyWords' => $keyWords,
            'data' => $result,
            'pagination' => array(
                'page' => $pagination['page'],
                'recordsPerPage' => $pagination['recordsPerPage'],
                'pages' => $pages,
                'records' => $records,
                'recordsThisPage' => count($result),
                'orderBy' => $pagination['orderBy'],
                'query' => $query,
            ),
        );
    }

    /**
     * Persiste en BD el objeto en curso
     *
     * Si se indica $data prevalecen estos valores a los
     * ya existentes en las propiedades del objeto. Es útil para
     * asignar valores vía array y no de forma individual a cada columna.
     *
     * Ejecuta los eventuales triggers beforeInsert y afterInsert definidos en $_triggers
     *
     * @param array $data Array de key=>value. Opcional.
     * @return mixed El valor de la primaryKey insertada
     */
    public function insert($data = array())
    {
        if (count($data)) {
            $this->bind($data);
        }

        $this->beforeInsert();

        $columns = $values = "";

        foreach (array_keys(self::$_tablesDescriptor[$this->_table]['columns']) as $column) {
            $columns .= "`{$column}`,";
            if (is_null($this->$column) || ($column == $this->_primaryKey && $this->$column == '')) {
                $values .= "NULL,";
            } else {
                $values .= "'{$this->$column}',";
            }
        }

        $columns = substr($columns, 0, -1);
        $values = substr($values, 0, -1);

        $ins = "INSERT INTO {$this->_table} ({$columns}) VALUES ({$values});";
        $this->setPrimaryKeyValue(DB::getInstance()->insert($ins));
        $this->_persisted = ($this->getPrimaryKeyValue() != '');

        if ($this->_persisted) {
            $this->afterInsert();
        }

        return $this->getPrimaryKeyValue();
    }


    /**
     * Actualiza un registro
     *
     * Ejecuta los eventuales triggers beforeUpdate y afterUpdate definidos en $_triggers
     *
     * @param array $data Array de key=>value. Opcional.
     * @return mixed El valor de la primaryKey o NULL en caso de fallo
     */
    public function update($data = array())
    {
        if (count($data)) {
            $this->bind($data);
        }

        $this->beforeUpdate();

        $values = "";

        foreach (array_keys(self::$_tablesDescriptor[$this->_table]['columns']) as $key) {
            if ($key != $this->_primaryKey) {
                if (is_null($this->$key)) {
                    $values .= "`" . $key . "` = NULL,";
                } else {
                    $values .= "`" . $key . "` = '" . addslashes($this->$key) . "',";
                }
            }
        }
        $values = substr($values, 0, -1);

        $query = "UPDATE {$this->_table} SET {$values} WHERE {$this->_primaryKey}='{$this->getPrimaryKeyValue()}';";

        $id = (DB::getInstance()->execSql($query) > 0) ? $this->getPrimaryKeyValue() : null;

        if ($id) {
            $this->_persisted = true;
            $this->afterUpdate();
        }

        return $id;

    }

    /**
     * Carga las propiedades del objeto con los valores de la tabla
     *
     * @return boolean True si el registro existe, False en caso contrario
     */
    public function load()
    {
        $filtro = "(`{$this->_primaryKey}`='{$this->getPrimaryKeyValue()}')";

        $qry = "SELECT * FROM {$this->_table} WHERE {$filtro};";
        $row = DB::getInstance()->select1Row($qry);

        if ($row) {
            // Cargar las propiedades
            foreach ($row as $key => $value) {
                //$column_name = str_replace('-', '_', $key);
                $this->$key = $value;
            }
        }

        $this->_persisted = ($row[$this->_primaryKey] != '');

        return $this->_persisted;
    }


    /**
     * Borra el objeto en curso
     *
     * Previo al borrado, se hace validación de integridad con entidades relacionadas.
     *
     * Si la relación es cascade=true, se borrarán los objetos relacionados.
     *
     * Si cascade=false y existen objetos relacionados se emite mensaje de
     * error y NO se produce el borrado.
     *
     * Ejecuta los eventuales triggers beforeDelete y afterDelete definidos en $_triggers
     *
     * @return boolean
     */
    public function delete()
    {
        $this->_errors = null;
        $result = false;

        // Validación de integridad con objetos relacionados
        if (count($this->_relations)) {

            foreach ($this->_relations as $relatedModel => $relationProperties) {

                $relatedObjects = $this->getRelatedObjects($relatedModel);

                $nRelations = count($relatedObjects);

                if ($nRelations) {
                    if ($relationProperties['cascade']) {
                        // Borrado en cascada N niveles
                        if (is_array($relatedObjects)) {
                            foreach ($relatedObjects as $relatedObject) {
                                $relatedObject->delete();
                            }
                        } elseif (is_object($relatedObjects)) {
                            $relatedObjects->delete();
                        }
                    } else {
                        // NO se hace el borrado
                        $this->_errors[] = "No se puede borrar, hay {$nRelations} relaciones con {$relatedModel}";
                    }
                }
            }

        }

        if ($this->_errors == null) {

            $this->beforeDelete();

            $query = "DELETE FROM `{$this->_table}` WHERE {$this->_primaryKey}='{$this->getPrimaryKeyValue()}';";
            //echo $query;
            $result = (DB::getInstance()->execSql($query) > 0);

            if ($result) {
                $this->afterDelete();
            }
        }

        return $result;
    }

    /**
     * Devuelve un objeto cuyo valor de la columna $columna es igual a $valor
     *
     * @param string $columna El nombre de la columna
     * @param variant $valor El valor a buscar
     * @return this El objeto encontrado
     */
    public function getBy($columna, $valor)
    {
        $query = "SELECT {$this->_primaryKey} FROM " . DB_NAME . ".`{$this->_table}` WHERE ({$columna} = '{$valor}')";
        //echo $query;
        $row = DB::getInstance()->select1Row($query);

        $id = (isset($row)) ? $row[$this->_primaryKey] : '';
        return new $this($id);
    }

    /**
     * Devuelve el valor de la columna $columnName
     *
     * Si se indica $idLanguage se devuelve el valor de la
     * propiedad $columnName_id$idLanguage, es decir el nombre
     * de la columna no debe indicar con el sufijo '_id'
     *
     * @param string $columnName El nombre de la columna
     * @param integer $idLanguage El número que identifica el idioma
     * @return mixed
     */
    public function getValue($columnName, $idLanguage = '')
    {
        if ($idLanguage == '') {
            $value = $this->{"$columnName"};
        } else {
            $columna = "{$columnName}_id{$idLanguage}";
            $value = $this->$columna;
        }

        return $value;
    }

    /**
     * Ejecuta una sentencia update sobre la entidad
     *
     * @param array $array Array de parejas columna, valor
     * @param string $condicion Condicion del where (sin el where)
     * @return int El número de filas afectadas
     */
    public function queryUpdate($array, $condicion = '1')
    {
        $valores = "";

        foreach ($array as $key => $value) {
            $valores .= "{$key}='{$value}',";
        }

        // Quito la coma final
        $valores = substr($valores, 0, -1);

        $query = "UPDATE " . DB_NAME . ".`{$this->_table}` SET {$valores} WHERE ({$condicion})";
        //echo $query,"<br/>";
        $filasAfectadas = DB::getInstance()->execSql($query);

        return $filasAfectadas;
    }

    /**
     * Ejecuta una sentencia delete sobre la entidad
     *
     * NOTA: Con este método no se hace validación de integridad
     *
     * @param string $condicion Condicion del where (sin el where)
     * @return int El número de filas afectadas
     */
    public function queryDelete($condicion)
    {
        $query = "DELETE FROM " . DB_NAME . ".`{$this->_table}` WHERE ({$condicion})";
        //echo $query;
        $filasAfectadas = DB::getInstance()->execSql($query);

        return $filasAfectadas;
    }

    /**
     * Devuelve  array de objetos/objeto/null que están relacionados con
     * el objeto en curso a través del modelo extranjeto $foreingModel
     * y cuya relación está definida en $this->_foreignRelations
     *
     * Si no se indica ningún modelo concreto, se devuelven todos los
     * objetos relacionados según $this->_foreignRelations
     *
     * Por ejemplo, para obtener el cliente relacionado con el pedido 23:
     *
     * en el modelo PedidosCab se debe indicar esta relacion:
     *
     * protected $_foreignRelations = array('Clientes' => 'cod_cliente',);
     *
     * y entonces:
     *
     * $p = new PedidosCab(23);
     * print_r($p->getForeignObject('Clientes'));
     *
     * Así se obtiene el objeto cliente asociado al pedido 23
     *
     * @param mixed String o NULL $foreingModel Nombre del modelo extranjero
     * @return mixed Objeto o array de objetos de entidad de datos
     */
    public function getForeignObject($foreingModel = null)
    {
        if ($foreingModel == null) {
            $result = array();
            foreach ($this->_foreignRelations as $foreingModel => $foreingKey) {
                if (class_exists($foreingModel)) {
                    $result[$foreingModel] = new $foreingModel($this->$foreingKey);
                }
            }
        } else {
            $result = null;
            if (class_exists($foreingModel)) {
                $foreingKey = $this->_foreignRelations[$foreingModel];
                $result = new $foreingModel($this->$foreingKey);
            }
        }

        return $result;
    }

    /**
     * Devuelve un objeto o array de objetos relacionados con el actual
     * dependiendo si la relación en one-to-one o one-to-many
     *
     * @param $relatedModel El nombre del modelo relacionado
     * @param $subSet Array con (nItems,filter,orderBy) para filtrar los objetos a devolver
     * @return mixed Objeto/Array de objetos entidad de datos
     */
    public function getRelatedObjects($relatedModel, $subSet = array())
    {
        $result = null;

        $relation = $this->_relations[$relatedModel];

        if (class_exists($relatedModel) && $relation['foreingKey']) {
            if ($relation['oneToMany']) {
                $obj = new $relatedModel();
                $orderBy = ($subSet['orderBy']) ? $subSet['orderBy'] : $relation['orderBy'];
                if (!$orderBy) {
                    $orderBy = "{$obj->_primaryKey} ASC";
                }
                $limit = ($subSet['nItems']) ? " LIMIT {$subSet['nItems']}" : "";
                $filter = ($subSet['filter']) ? "AND {$subSet['filter']}" : "";
                $rows = $obj->querySelect($obj->_primaryKey, "{$relation['foreingKey']}='{$this->getPrimaryKeyValue()}' {$filter}", $orderBy . $limit);
                foreach ($rows as $row) {
                    $result[] = new $relatedModel($row[$obj->_primaryKey]);
                }
            } else {
                $obj = new $relatedModel();
                $result = $obj->getBy($relation['foreingKey'], $this->getPrimaryKeyValue());
            }
        }

        return $result;
    }

    /**
     * Devuelve un array con todos los registros de la entidad
     *
     * Cada elemento tiene la primarykey y el valor de $column
     *
     * Si no se indica valor para $column, se mostrará los valores
     * de la primarykey
     *
     * Su utilidad es básicamente para generar listas desplegables de valores
     *
     * El array devuelto es:
     *
     * array (
     *      '0' => array('Id' => valor primaryKey, 'Value'=> valor de la columna $column),
     *      '1' => .......
     * )
     *
     * @param string $column El nombre de columna a mostrar
     * @param boolean $default Si se añade o no el valor 'Indique Valor'
     * @return array Array de valores Id, Value
     */
    public function fetchAll($column = '', $default = true)
    {
        if ($column == '') {
            $column = $this->_primaryKey;
        }

        $rows = $this->querySelect($this->_primaryKey . " as Id, {$column} as Value", "1", "{$column} ASC");

        if ($default == TRUE) {
            array_unshift($rows, array('Id' => '', 'Value' => ':: Indique un Valor'));
        }

        return $rows;
    }

    /**
     * Devuelve array con los valores distintos que se encuentran
     * en la tabla para la columna $column
     *
     * @param string $column
     * @param string $condicion Condición del where (sin el where)
     * @return array
     */
    public function getDistinctValues($column, $condicion = '1')
    {
        $array = array();

        $rows = $this->querySelect("DISTINCT {$column}", $condicion);
        foreach ($rows as $row) {
            $array[] = $row[$column];
        }

        return $array;
    }

    /**
     * Devuelve array con los valores posibles para la columna $columnName
     * que se han definido en el modelo en $_listValues
     *
     * @param string $columnName
     * @return array
     */
    public function getListValues($columnName)
    {
        return (is_array($this->_listValues[$columnName]) ? $this->_listValues[$columnName] : array());
    }

    /**
     * Devuelve la descripción que correspondiente al valor
     * posible de la columna $columnName y el valor $key
     *
     * @param string $columnName
     * @param string $key
     * @return string
     */
    public function getListValue($columnName, $key)
    {
        return $this->_listValues[$columnName][$key];
    }

    /**
     * Ejecuta una sentencia SELECT sobre la entidad
     *
     * @param string $columnas Las columnas a obtener separadas por comas
     * @param string $condicion Condición del where (sin el where)
     * @param string $orden Criterio de orden
     * @return array Array de resultado
     */
    public function querySelect($columnas, $condicion = '1', $orden = '')
    {
        $rows = array();

        $orden = ($orden == '') ? '' : "ORDER BY {$orden}";

        $query = "SELECT {$columnas} FROM " . DB_NAME . ".`{$this->_table}` WHERE {$condicion} {$orden}";
        //echo $query,"<br/>";exit;
        $rows = DB::getInstance()->select($query);

        return $rows;
    }

    /**
     * Carga las propiedades del objeto con los valores pasados en el array.
     *
     * Los índices del array deben coincidir con los nombre de las propiedades.
     *
     * Las propiedades que no tengan correspondencia con elementos del array no serán cargadas.
     *
     * Los índices que no se correspondan con propiedades del objeto no se tendrán en cuenta.
     *
     * La función de este método equivale a realizar manualmente todos los
     * set's de las propiedades del objeto.
     *
     * @param array $datos
     * @return void
     */
    public function bind(array $data)
    {
        foreach ($data as $key => $value) {
            if (isset(self::$_tablesDescriptor[$this->_table]['columns'][$key])) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Devuelve un array cuyo índice es el nombre de la propiedad
     * y el valor es el valor de dicha propiedad
     * No devuelve las propiedades que empiezan por guión bajo "_"
     *
     * @param array $arrayColumns Array con los nombres de las columnas que se quieren obtener. Por defecto todas
     * @return array Array con los valores de las propiedades de la entidad
     */
    public function iterator($arrayColumns = array())
    {
        $values = array();

        if (!count($arrayColumns)) {
            foreach (array_keys(self::$_tablesDescriptor[$this->_table]['columns']) as $key) {
                $values[$key] = $this->$key;
            }
        } else {
            foreach ($arrayColumns as $key) {
                $values[$key] = $this->$key;
            }
        }

        return $values;
    }

    /**
     * Devuelve un array con los nombres de las propiedades de la entidad.
     * No devuelve las propiedades que empiezan por guión bajo "_"
     *
     * @return array Array con los valores de las propiedades de la entidad
     */
    public function getColumnsNames()
    {
        $columns = array();

        foreach (array_keys(self::$_tablesDescriptor[$this->_table]['columns']) as $key) {
            $columns[] = $key;
        }

        return $columns;
    }


    /**
     * Devuelve el objeto en formato JSON
     *
     * @param array $arrayColumns Array con los nombres de las columnas que se quieren obtener. Por defecto todas
     * @return json
     */
    public function json($arrayColumns = array())
    {
        return json_encode($this->iterator($arrayColumns));
    }

    /**
     * Devuelve el objeto serializado
     *
     * @param array $arrayColumns Array con los nombres de las columnas que se quieren obtener. Por defecto todas
     * @return string
     */
    public function serialize($arrayColumns = array())
    {
        return serialize($this->iterator($arrayColumns));
    }

    /**
     * Devuelve el número de registros que tiene la entidad
     *
     * @param string $criterio Clausa para el WHERE para poder contar un subconjunto de registros
     * @return integer
     */
    public function getNumberOfRecords($criterio = '1')
    {
        $query = "SELECT COUNT({$this->getPrimaryKeyName()}) as NumeroDeRegistros FROM `{$this->_table}` WHERE ({$criterio})";
        $row = DB::getInstance()->select1Row($query);
        $nRegistros = $row['NumeroDeRegistros'];

        return $nRegistros;
    }

    /**
     * Valida antes de persistir un objeto.
     *
     * Comprueba que los campos NO nulos tengan valor según
     * el descriptor de la tabla.
     *
     * Comprueba la unicidad de los índices UNIQUE.
     *
     * Valida las eventuales reglas definidas en $_constrains.
     *
     * Se pueden indicar constrains de forma explícita para cada columna
     * en el parámetro $constrains, en cuyo caso estos prevalecen a los
     * definidos en el modelo.
     *
     * @param array $constrains Array con los constrains de cada columna. Opcional
     * @return bool
     */
    public function validate($constrains = array())
    {

        $this->_errors = array();

        $this->logicValidation();

        foreach (self::getTableDescriptor() as $column => $properties) {

            // Validación de NO NULL. La columna auto_increment no se tiene en cuenta
            if (($properties['Extra'] !== 'auto_increment') && ($properties['Null'] == 'NO') && (!isset($this->$column) || trim($this->$column) == '')) {
                $this->_errors[] = "{$column} : Valor requerido";
            }

            // Validación deL índice PRIMARYKEY
            if ($properties['Key'] == "PRI") {
                $oldObject = $this->getBy($column, $this->$column);
                if ($oldObject->_persisted && !$this->_persisted) {
                    $this->_errors[] = "{$column}: El valor debe ser único, ya está siendo utilizado";
                }
                unset($oldObject);
            }

            // Validación de índices UNIQUE
            if ($properties['Key'] == "UNI") {
                $oldObject = $this->getBy($column, $this->$column);
                if ($oldObject->_persisted && ($oldObject->getPrimaryKeyValue() != $this->getPrimaryKeyValue())) {
                    $this->_errors[] = "{$column}: El valor debe ser único, ya está siendo utilizado";
                }
                unset($oldObject);
            }

            // Para cada columna se utilizan los constrains definidos en el modelo
            // a no ser que se hayan indicado otros en el parámetro $constrains
            $constrain = (isset($constrains[$column])) ? $constrains[$column] : $this->_constrains[$column];

            // Validación por valor mínimo y máximo
            if (isset($constrain['value'])) {
                if (($this->$column < $constrain['value']['min']) || ($this->$column > $constrain['value']['max'])) {
                    $this->_errors[] = "{$column} : Valor {$this->$column} fuera del rango {$constrain['value']['min']} a {$constrain['value']['max']}";
                }
            }

            // Validación por longitud de caracteres mínima y máxima
            if (isset($constrain['length'])) {
                $length = strlen($this->$column);
                if (($length < $constrain['length']['min']) || ($length > $constrain['length']['max'])) {
                    $this->_errors[] = "{$column} : Se requieren de {$constrain['length']['min']} a {$constrain['length']['max']} caracteres.";
                }
            }

            // Validación por lista de valores posibles
            if (isset($constrain['values'])) {
                if (!in_array($this->$column, $constrain['values'])) {
                    $allowedValues = "";
                    foreach ($constrain['values'] as $value) {
                        $allowedValues .= "{$value},";
                    }
                    $allowedValues = substr($allowedValues, 0, -1);
                    $this->_errors[] = "{$column} : El valor {$this->$column} no está entre los permitidos ({$allowedValues})";
                }
            }
        }

        return (count($this->_errors) == 0);
    }

    /**
     * Validaciones lógicas intrínsecas a cada modelo.
     *
     * Por ejemplo, que la fechaHasta no sea anterior a la fechaDesde, o
     * que el IBAN o email estén bien formados.
     *
     * Son validaciones en base a la lógica de negocio y que no se pueden
     * hacer vía $_constrains
     *
     * @return bool
     */
    public function logicValidation()
    {

        // TODO: lógica de validación y poner los eventuales errores en $this->_errors

        //if ($this->poblacion == 'Granada') {
        //  $this->_errors[] = "No se puede ser de Granada";
        //}

        return (count($this->_errors) == 0);
    }

    /**
     * Devuelve el array de eventuales mensajes de error
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * Devuelve el array de eventuales mensajes de alerta
     *
     * @return array
     */
    public function getAlerts()
    {
        return $this->_alerts;
    }

    /**
     * Devuelve el nombre de la PrimaryKey de la entidad
     *
     * @return string PrimaryKey Name
     */
    public function getPrimaryKeyName()
    {
        return $this->_primaryKey;
    }

    /**
     * Le asigna un valor a la propiedad que corresponde a la primaryKey
     *
     * @param mixed $primaryKeyValue
     */
    public function setPrimaryKeyValue($primaryKeyValue)
    {
        $this->{$this->_primaryKey} = $primaryKeyValue;
    }

    /**
     * Devuelve el valor de la primarykey del objeto actual
     *
     * @return mixed PrimaryKey Value
     */
    public function getPrimaryKeyValue()
    {
        return $this->{"$this->_primaryKey"};
    }

    /**
     * Devuelve el nombre de la tabla física que representa la entidad
     *
     * @return string _tableName
     */
    public function getTableName()
    {
        return $this->_table;
    }

    /**
     * Devuelve el nombre completo de la tabla (baseDeDatos.tabla)
     *
     * @return string
     */
    public function getFullTableName()
    {
        return DB_NAME . "." . $this->_table;
    }

    /**
     * Devuelve true/false indicando si el objeto está
     * almacenado en la base de datos
     *
     * @return boolean
     */
    public function isPersisted()
    {
        return $this->_persisted;
    }

    /**
     * Acciones a realizar antes de insertar
     *
     * @return void
     */
    protected function beforeInsert()
    {
        // TODO Implementar en cada modelo si procede
    }

    /**
     * Acciones a realizar después de insertar
     *
     * @return void
     */
    protected function afterInsert()
    {
        // TODO Implementar en cada modelo si procede
    }

    /**
     * Acciones a realizar antes de actualizar
     *
     * @return void
     */
    protected function beforeUpdate()
    {
        // TODO Implementar en cada modelo si procede
    }

    /**
     * Acciones a realizar después de actualizar
     *
     * @return void
     */
    protected function afterUpdate()
    {
        // TODO Implementar en cada modelo si procede
    }

    /**
     * Acciones a realizar antes de borrar
     *
     * @return void
     */
    protected function beforeDelete()
    {
        // TODO Implementar en cada modelo si procede
    }

    /**
     * Acciones a realizar después de borrar
     *
     * NO implementar aquí las acciones relacionadas con
     * la integridad referencial ya que de eso se encargan
     * los $_relations
     *
     * @return void
     */
    protected function afterDelete()
    {
        // TODO Implementar en cada modelo si procede
    }

    /**
     * Carga la tabla con datos de ejemplo
     *
     * @param bool $truncate Si true, previa a la carga se vacia el contenido
     * @param array $fixtures Array con los datos. Si no se indica se toman los definidos en $_fixtures del modelo.
     * @return void
     */
    public function loadFixtures($truncate = true, $fixtures = array())
    {

        if (!count($fixtures)) {
            $fixtures = $this->_fixtures;
        }

        if (count($fixtures)) {

            if ($truncate) {
                $this->truncate();
            }

            foreach ($fixtures as $record) {
                $obj = new $this();
                $obj->bind($record);
                if ($obj->validate()) {
                    $obj->insert();
                }
            }
        }
    }

    /**
     * Llena el modelo en curso con datos de prueba
     * generados aleatoriamente con la factoría Faker
     *
     * Previa a la inserción se realiza la validación lógica y de constrains.
     *
     * @link https://packagist.org/packages/fzaninotto/faker#user-content-basic-usage
     *
     * @param bool $truncate Si true, vacia la tabla
     * @param int $nItems Número de rows a insertar
     * @return array Array con tres elementos 'nRows' => n. de rows insertadas, 'errors' => array de errores, 'timeLap' => segundos empleados
     */
    public function seeder($truncate = true, $nItems = 10)
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/fzaninotto/faker/src/autoload.php';

        $nRowsInserted = 0;
        $errors = array();

        $faker = Faker\Factory::create();

        if ($truncate) {
            $this->truncate();
        }

        $timeStart = microtime(true);

        for ($i = 0; $i < $nItems; $i++) {
            $object = new $this();

            foreach (self::getTableDescriptor() as $column => $properties) {

                // La columna auto_increment no se tiene en cuenta
                if ($properties['Extra'] !== 'auto_increment') {
                    switch ($properties['Type']) {
                        case 'char':
                            $object->$column = $faker->word;
                            break;
                        case 'varchar':
                            if ($properties['Length'] > 3) {
                                $object->$column = $faker->text($properties['Length']);
                            } else {
                                $object->$column = $faker->word;
                            }
                            break;
                        case 'text':
                            $object->$column = $faker->text();
                            break;
                        case 'int':
                        case 'bigint':
                            $object->$column = $faker->randomNumber();
                            break;
                        case 'date':
                            //$object->$column = $faker->date('Y-m-d');
                            break;
                        case 'datetime':
                            //$object->$column = $faker->dateTimeThisCentury();
                            break;
                    }
                }
            }

            if ($object->validate()) {
                if ($object->insert()) {
                    $nRowsInserted++;
                }
            } else {
                foreach ($object->_errors as $error) {
                    array_push($errors, $error);
                }
            }
        }

        $timeEnd = microtime(true);

        return array(
            'nRows' => $nRowsInserted,
            'errors' => $errors,
            'timeLap' => round($timeEnd - $timeStart, 3) . ' segundos',
        );
    }

    /**
     * Borra el contenido de la tabla.
     *
     * @return void
     */
    private function truncate()
    {
        $query = "TRUNCATE TABLE " . DB_NAME . ".`{$this->_table}`";
        DB::getInstance()->execSql($query);
    }

    /**
     * Devuelve array con el descriptor de toda la tabla
     * o de la columna indicada en $column
     *
     * @param string $column
     * @return array
     */
    public function getTableDescriptor($column = '')
    {
        return ($column) ? self::$_tablesDescriptor[$this->_table]['columns'][$column] : self::$_tablesDescriptor[$this->_table]['columns'];
    }

    /**
     * Devuelve array con el descriptor de la entidad en curso.
     *
     * Comprueba si está cacheada, si no la cachea.
     *
     * @return array Array
     */
    private function cacheaTableDescriptor()
    {
        $descriptor = array();

        if (is_file($this->_cacheFile)) {
            $descriptor = unserialize(file_get_contents($this->_cacheFile));
        }

        if (!$descriptor[$this->_table]) {
            $obj = new TableDescriptor(array(), $this->_table);
            $descriptor[$this->_table] = $obj->getDescriptor();
            file_put_contents($this->_cacheFile, serialize($descriptor), LOCK_EX);
        }

        $this->_primaryKey = $descriptor[$this->_table]['primaryKey'];

        return $descriptor[$this->_table];
    }
}