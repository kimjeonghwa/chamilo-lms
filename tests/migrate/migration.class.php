<?php
/**
 * Scipt defining the Migration class
 * @package chamilo.migration
 */

/**
 * Migration class (ease the migration work). This class *must* be extended
 * in a database server-specific implementation as migration.[DB].class.php
 */
class Migration {

    /**
     * Origin DB type holder
     */
    public $odbtype = '';

    /**
     * Origin DB host holder
     */
    public $odbhost = '';

    /**
     * Origin DB port holder
     */
    public $odbport = '';

    /**
     * Origin DB user holder
     */
    public $odbuser = '';

    /**
     * Origin DB password holder
     */
    public $odbpass = '';

    /**
     * Origin DB name holder
     */
    public $odbname = '';

    /**
     * Array holding all errors/warnings ocurring during one execution
     */
    public $errors_stack = array();

    /**
     * Temporary handler for SQL result
     */
    public $odbrows = null;    

    /**
     * Temporary holder for the list of users, courses and sessions and their 
     * data. Store values here (preferably using the same indexes as the
     * destination database field names) until ready to insert into Chamilo.
     */
    public $data_list = array(
      'boost_users' => false,
      'users' => array(), 
      'boost_courses' => false,
      'courses' => array(),
      'boost_sessions' => false,
      'sessions' => array(),
    );
    
    public $web_service_connection_info = array();
    
    /**
     * The constructor assigns all database connection details to the migration
     * object
     * @param string The original database's host
     * @param string The original database's port
     * @param string The original database's user
     * @param string The original database's password
     * @param string The original database's name
     * @return boolean False on error. Void on success.
     */
    public function __construct($dbhost = null, $dbport = null, $dbuser = null, $dbpass = null, $dbname = null, $boost = false) {
        if (empty($dbhost) || empty($dbport) || empty($dbuser) || empty($dbpass) || empty($dbname)) {
            $this->errors_stack[] = 'All origin database params must be given. Received ' . print_r(func_get_args(), 1);
            //return false;
        }
        //$this->odbtype = $dbtype;
        $this->odbhost = $dbhost;
        $this->odbport = $dbport;
        $this->odbuser = $dbuser;
        $this->odbpass = $dbpass;
        $this->odbname = $dbname;
        
        // Set the boost level if set in config.php
        if (!empty($boost) && is_array($boost)) {
            foreach ($boost as $item => $val) {
                if ($val == true) {
                    $this->data_list[$item] = true;
                }
            }
        }
    }

    /**
     * The connect method should be extended by the child class
     */
    public function connect() {
        //extend in child class
    }
    
    public function set_web_service_connection_info($matches) {        
        $this->web_service_connection_info = $matches['web_service_calls'];
    }

    /**
     * The migrate method launches the migration process based on an array of
     * tables and fields matches defined in the given array.
     * @param array Structured array of matches (see migrate.php)
     */
    public function migrate($matches) {
        error_log("\n" . '------------ ['.date('H:i:s').'] Migration->migrate function called ------------' . "\n");
        $extra_fields = array();
        // Browsing through 1st-level arrays in db_matches.php
        foreach ($matches as $idx => $table) {
            if ($idx === 'web_service_calls') { continue;}
            echo "Starting table ".$table['orig_table']." at ".date('h:i:s')."\n";
            error_log('['.date('H:i:s').'] Found table ' . $table['orig_table'] . ' in db_matches');
            $build_only = false;

            if (empty($table['dest_table'])) {
                //If there is no destination for this table, report
                error_log(' ... which is just for data collection');
                $build_only = true;
            }

            // Creating extra fields if necessary inside Chamilo (to store 
            // original fields)
            if (isset($table['extra_fields']) && in_array($table['dest_table'], array('course', 'user', 'session'))) {
                $extra_fields = self::_create_extra_fields($table);
            }

            // Process the migration of fields from the given table
            $sql_select_fields = self::prepare_field_match($table);
            $this->select_all($table['orig_table'], $sql_select_fields, $table);

            if (count($table['fields_match']) == 0) {
                error_log('No fields found');
                continue;
            }
            $num_rows = $this->num_rows();

            if ($num_rows) {
                error_log('Records found: ' . $num_rows);
                $item = 1;
                $lastpct = 0;
                //error_log(print_r($table['extra_fields'],1));
                while ($row = $this->fetch_array()) {
                    self::execute_field_match($table, $row, $extra_fields);
                    $percentage = ($item / $num_rows) * 100;
                    $newpct = intval($percentage);
                    if ($newpct>$lastpct && floor($percentage) % 10 == 0) {
                        $percentage = round($percentage, 3);
                        $lastpct = $newpct;
                        error_log("Processing item {$table['orig_table']} #$item $percentage%");
                    }
                    $item++;
                }
                error_log('Finished processing table ' . $table['orig_table'] . " \n\n");
            } else {
                error_log('No records found');
            }

            //Stop here (only for tests)
            if ($table['orig_table'] == 'gradebook_evaluation_type') {
                exit;
            }
        }
    }
    
    /**
     * Call the SOAP web service as detailed in the parameters
     * @param array Settings for the WS call
     * @param string Name of the funcion to call
     * @param array Variables to be passed as params to the function
     * @return array Results as returned by the SOAP call
     */
    static function soap_call($web_service_params, $function_name, $params = array()) {
        
        //$web_service_params = $this->web_service_connection_info;
        
        // Create the client instance
        $url = $web_service_params['url'];        
        try {
            $client = new SoapClient($url);
        } catch (SoapFault $fault) {
            $error = 1;
            die('Error connecting');
        }
        
        $client->debug_flag = true;     
        try {            
            $data = $client->$function_name($params);            
        } catch (SoapFault $fault) {
            $error = 2;
            //die("Problem querying service - $function_name");
            return array(
                'error' => true,
                'message' => "Problem querying service - $function_name in URL $url ",
                'status_id' => 0
            );
        }
        
        if (!empty($data)) {
            error_log("Calling MigrationCustom::$function_name  $url with params: ".print_r($params,1));
            return MigrationCustom::$function_name($data, $params);           
        } else {
            return array(
                'error' => true,
                'message' => 'No Data found',
                'status_id' => 0
            );
        }        
    }
    
    /**
     * Test a series of hand-crafted transactions
     * @param array of parameters that would usually get passed to the web service
     * @param bool Whether to truncate the transaction table before the test or not
     * @return void
     */
    function test_transactions($truncate = false) {
        error_log('test_transactions');
        //Just for tests
        
        //Cleaning transaction table
        if ($truncate) {
            $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
            $sql = "TRUNCATE $table";
            Database::query($sql);
        }
        
        $transaction_harcoded = array(
            array(
                //'action' => 'usuario_agregar',
                'action' => 1,
                'item_id' =>  'D236776B-D7A5-47FF-8328-55EBE9A59015',
                'orig_id' => null,
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            array(
                //'action' => 'usuario_editar',
                'action' => 3,
                'item_id' => 'D236776B-D7A5-47FF-8328-55EBE9A59015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),            
            array(
                //'action' => 'usuario_eliminar',
                'action' => 2,
                'item_id' =>  'D236776B-D7A5-47FF-8328-55EBE9A59015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),      
            array(
                //'action' => 'usuario_matricula',
                'action' => 4,
                'item_id' =>  '95EDA88F-D729-450F-95FF-4A3989244F53', //usuario - Abel 
                'orig_id' => null, //session orig
                'dest_id' => 'C3671999-095E-4018-9826-678BAFF595DF', //session dest
                'branch_id' => 1,                
                'status_id' => 0
            ),
            array(
                //'action' => 'curso_agregar',
                'action' => 5,
                'item_id' =>  'E2334974-9D55-4BB4-8B57-FCEFBE2510DC',
                'orig_id' => null,
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
            array(
                //'action' => 'curso_eliminar',
                'action' => 6,
                'item_id' =>  'E2334974-9D55-4BB4-8B57-FCEFBE2510DC',
                'orig_id' => null,
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
            array(
                //'action' => 'curso_editar',
                'action' => 7,
                'item_id' =>  '31B4BD38-5D90-4275-88AF-F01F0274800A', // ONE   (SATURDAYS)
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
/*            array(
                //'action' => 'curso_matricula',
                'item_id' =>  'E2334974-9D55-4BB4-8B57-FCEFBE2510DC', //course
                'orig_id' => null,
                'dest_id' => 'C3671999-095E-4018-9826-678BAFF595DF', //session
                'branch_id' => 1,                
                'status_id' => 0
            ),
*/
            array(
                //'action' => 'pa_agregar',
                'action' => 8,
                'item_id' =>  'C3671999-095E-4018-9826-678BAFF595DF',
                'orig_id' => null,
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
            array(
                //'action' => 'pa_editar',
                'action' => 10,
                'item_id' =>  'C3671999-095E-4018-9826-678BAFF595DF',
                'orig_id' => '0',
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
            array(
                //'action' => 'pa_eliminar',
                'action' => 9,
                'item_id' =>  'C3671999-095E-4018-9826-678BAFF595DF', //id to delete
                'orig_id' => null,
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            
            /* seems not to be used
            array(
                //'action' => 'pa_cambiar_aula',
                'action' => 11,
                'item_id' =>  'C3671999-095E-4018-9826-678BAFF595DF',
                'orig_id' => '0',
                'dest_id' => '',
                'branch_id' => 1,                
                'status_id' => 0
            ),*/
            array(
                //'action' => 'pa_cambiar_horario',
                'action' => 12,
                'item_id' =>  'B94FEBA2-7EAD-4E14-B3DA-1D02397D1FA1', //session id  - 200910 (A02M) Advanced Oral Communication Skills 2 08:45 10:15 701 00003
                'orig_id' => '63D661DB-0A2F-47FC-94C0-5AA46BE7DA66',  // (01) 07:00 09:00
                'branch_id' => 1,
                'dest_id' => 'B4FE6E83-F33F-417B-8B3F-C24CB94264EA', //(02) 09:00 11:00
                'status_id' => 0
            ),
/*             array(
                //'action' => 'pa_cambiar_sede',
                'action' => 'x',
                'item_id' =>  'C3671999-095E-4018-9826-678BAFF595DF',//session id
                'orig_id' => '0',
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
             array(
                'action' => 'cambiar_pa_fase',
                'item_id' => 'C3671999-095E-4018-9826-678BAFF595DF',//session id
                'orig_id' => '0',
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
             array(
                'action' => 'cambiar_pa_intensidad',
                'item_id' =>  'C3671999-095E-4018-9826-678BAFF595DF',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
*/
            array(
                //'action' => 'horario_agregar',
                'action' => 13,
                'item_id' =>  'E395895A-B480-456F-87F2-36B3A1EBB81C',  // horario
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
             array(
                //'action' => 'horario_editar',
                'action' => 15,
                'item_id' =>  'E395895A-B480-456F-87F2-36B3A1EBB81C',
                'orig_id' => '0',
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
             array(
                //'action' => 'horario_eliminar',
                'action' => 14,
                'item_id' =>  'E395895A-B480-456F-87F2-36B3A1EBB81C',
                'orig_id' => '0',
                'dest_id' => null,
                'branch_id' => 1,                
                'status_id' => 0
            ),
            /*
            array(
                //'action' => 'aula_agregar',
                'action' => 16,
                'item_id' =>  '1',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
             array(
                //'action' => 'aula_eliminar',
                'action' => 17,
                'item_id' =>  '1',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
             array(
                //'action' => 'aula_editar',
                'action' => 18,
                'item_id' =>  '1',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
             * */
            array(
                //'action' => 'sede_agregar',
                'action' => 19,
                'item_id' =>  '7379A7D3-6DC5-42CA-9ED4-97367519F1D9',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            array(
                //'action' => 'sede_editar',
                'action' => 21,
                'item_id' =>  '7379A7D3-6DC5-42CA-9ED4-97367519F1D9',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            array(
                //'action' => 'sede_eliminar',
                'action' => 20,
                'item_id' =>  '7379A7D3-6DC5-42CA-9ED4-97367519F1D9',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            array(
                //'action' => 'frecuencia_agregar',
                'action' => 22,
                'item_id' =>  '0091CD3B-F042-11D7-B338-0050DAB14015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            array(
                //'action' => 'frecuencia_editar',
                'action' => 24,
                'item_id' =>  '0091CD3B-F042-11D7-B338-0050DAB14015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
             array(
                //'action' => 'frecuencia_eliminar',
                'action' => 23,
                'item_id' =>  '0091CD3B-F042-11D7-B338-0050DAB14015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            array(
                //'action' => 'intensidad_agregar',
                'action' => 25,
                'item_id' =>  '0091CD3C-F042-11D7-B338-0050DAB14015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
            array(
                //'action' => 'intensidad_editar',
                'action' => 27,
                'item_id' =>  '0091CD3C-F042-11D7-B338-0050DAB14015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
             array(
                //'action' => 'intensidad_eliminar',
                'action' => 26,
                'item_id' =>  '0091CD3C-F042-11D7-B338-0050DAB14015',
                'orig_id' => '0',
                'branch_id' => 1,
                'dest_id' => null,
                'status_id' => 0
            ),
        );
        
        foreach( $transaction_harcoded as  $transaction) {        
            self::add_transaction($transaction);
        }        
    }

    /**
     * Adds a given transaction to the transactions table in Chamilo
     * @param array The transaction details (array('id' => ..., 'action' => '...', ...))
     * @return int The ID of the transaction row in Chamilo's table
     */
    static function add_transaction($params) {
        error_log('Requested add_transaction of : '.print_r($params,1));
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        if (isset($params['id'])) {
            unset($params['id']);
        }
        $params['time_update'] = $params['time_insert'] = api_get_utc_datetime();        
        
        $inserted_id = Database::insert($table, $params);
        if ($inserted_id) {
            error_log("Transaction added #$inserted_id");
        }
        return $inserted_id;        
    }
    
    /**
     * Get all available branches (the migration system supports multiple origin databases, the branch identifies which database it comes from)
     * @return array Branches IDs (int)
     */
    static function get_branches() {
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        $sql = "SELECT DISTINCT branch_id FROM $table ORDER BY branch_id";
        $result = Database::query($sql);
        if (Database::num_rows($result) > 0) {
          return Database::store_result($result, 'ASSOC');
        }
        return array(
          //0=>array('branch_id' => 1),
          1=>array('branch_id' => 2),
          //2=>array('branch_id' => 3),
          //3=>array('branch_id' => 4),
          //4=>array('branch_id' => 5),
        );
    }
    
    /**
     * Gets transactions in a specific state (for example to get all non-processed transactions) from the Chamilo transactions table
     * @param int State ID (0=unprocessed (default), 2=completed)
     * @param int Branch ID
     * @return array Associative array containing the details of the transactions requested
     */
    static function get_transactions($status_id = 0, $branch_id = null) {
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        $branch_id = intval($branch_id);
        $status_id = intval($status_id);
        
        $extra_conditions = null;
        if (!empty($branch_id)) {
            $extra_conditions = " AND branch_id = $branch_id ";
        }
        $sql = "SELECT * FROM $table WHERE status_id = $status_id $extra_conditions ORDER BY id ";
        $result = Database::query($sql);        
        return Database::store_result($result, 'ASSOC');
    }
    
    static function get_transaction_by_transaction_id($transaction_id, $branch_id) {
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        $transaction_id = intval($transaction_id);
        $branch_id = intval($branch_id);
        
        if (!empty($transaction_id) && !empty($branch_id)) {
            $sql = "SELECT * FROM $table WHERE transaction_id = $transaction_id  AND branch_id = $branch_id";
            $result = Database::query($sql);
            if (Database::num_rows($result)) {
                return Database::fetch_array($result, 'ASSOC');        
            }
        }
        return false;
    }
    
    static function delete_transaction_by_transaction_id($transaction_id, $branch_id) {
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        $transaction_id = intval($transaction_id);
        $branch_id = intval($branch_id);
        
        if (!empty($transaction_id) && !empty($branch_id)) {
            $sql = "DELETE FROM $table WHERE transaction_id = $transaction_id  AND branch_id = $branch_id";
            Database::query($sql);            
        }        
    }
        
    
    /**
     * Gets the latest completed transaction for a specific branch (allows the building of a request to the branch to get new transactions)
     * @param int The ID of the branch
     * @return int The ID of the latest transaction
     */
    static function get_latest_completed_transaction_by_branch($branch_id) {
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        $branch_id = intval($branch_id);
        $sql = "SELECT id FROM $table WHERE status_id = 2 AND branch_id = $branch_id ORDER BY id DESC  LIMIT 1";
        $result = Database::query($sql);
        if (Database::num_rows($result)) {
            $row = Database::fetch_array($result);
            return $row['id'];
        }
        return 0;
    }
    
    /**
     * Gets the latest locally-recorded transaction for a specific branch
     * @param int The ID of the branch
     * @return int The ID of the last transaction registered
     */
    static function get_latest_transaction_id_by_branch($branch_id) {
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        $branch_id = intval($branch_id);
        $sql = "SELECT transaction_id FROM $table 
                WHERE branch_id = $branch_id 
                ORDER BY transaction_id DESC 
                LIMIT 1";
        $result = Database::query($sql);
        if (Database::num_rows($result)) {
            $row = Database::fetch_array($result);
            return $row['transaction_id'];
        }
        return 376011;
    }
    
    /**
     * Gets a specific transaction using select parameters
     * @param array Select parameters (associative array)
     * @param string Type of result set expected
     * @return array Results as requested
     */
    static function get_transaction_by_params($params, $type_result = 'all') {
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        return Database::select('*', $table, $params, $type_result);
    }
    
    /**
     * Updates a transaction using the given query parameters
     * @param array Query parameters
     * @return bool The result of the transaction row update
     */ 
    static function update_transaction($params) {
        //return false;
        $table = Database::get_main_table(TABLE_MIGRATION_TRANSACTION);
        if (empty($params['id'])) {
            error_log('No transaction id provided during update_transaction');
            return false;
        }
        $params['time_update'] = api_get_utc_datetime();
        error_log("Transaction updated #{$params['id']} with status_id = {$params['status_id']}");
        
        //Failed - do something else
        if ($params['status_id'] == MigrationCustom::TRANSACTION_STATUS_FAILED) {
            //event_system($event_type, $event_value_type, $event_value, $datetime = null, $user_id = null, $course_code = null) {
            event_system('transaction_error', 'transaction_id', $params['id'], $params['time_update']);
        }
        
        return Database::update($table, $params, array('id = ?' => $params['id']));
    }
    
    /**
     * Search for new transactions through a web service call. Automatically insert them in the local transactions table.
     * @param array The web service parameters
     * @param int An optional transaction ID to start from. If none provided, fetches the latest transaction available and add + 1
     * @return The operation results
     */
    function search_transactions($transaction_id = null, $branch_id = null) {
        error_log('search_transactions');
        
        //Testing transactions        
        $web_service_params = $this->web_service_connection_info;
                
        /*$result = self::soap_call($web_service_params,'usuarioDetalles', array('uididpersona' => 'D236776B-D7A5-47FF-8328-55EBE9A59015'));
        $result = self::soap_call($web_service_params,'programaDetalles', array('uididprograma' => 'C3671999-095E-4018-9826-678BAFF595DF'));
        $result = self::soap_call($web_service_params,'cursoDetalles', array('uididcurso' => 'E2334974-9D55-4BB4-8B57-FCEFBE2510DC'));        
        $result = self::soap_call($web_service_params,'faseDetalles', array('uididfase' => 'EBF63F1C-FBD7-46A5-B039-80B5AF064929'));
        $result = self::soap_call($web_service_params,'frecuenciaDetalles', array('uididfrecuencia' => '0091CD3B-F042-11D7-B338-0050DAB14015'));
        $result = self::soap_call($web_service_params,'intensidadDetalles', array('uididintensidad' => '0091CD3C-F042-11D7-B338-0050DAB14015'));
        $result = self::soap_call($web_service_params,'mesesDetalles', array('uididfase' => 'EBF63F1C-FBD7-46A5-B039-80B5AF064929'));
        $result = self::soap_call($web_service_params,'sedeDetalles', array('uididsede' => '7379A7D3-6DC5-42CA-9ED4-97367519F1D9'));        
        $result = self::soap_call($web_service_params,'horarioDetalles', array('uididhorario' => 'E395895A-B480-456F-87F2-36B3A1EBB81C'));        
        $result = self::soap_call($web_service_params,'transacciones', array('ultimo' => 354911, 'cantidad' => 2));         
        */
        if (empty($branch_id)) {
            $branches = self::get_branches();    
        } else {
            $branches = array('branch_id' => $branch_id);
        }
        
        foreach ($branches as $branch) {
            error_log('Treating transactions for branch '.$branch['branch_id']);
            if (empty($transaction_id)) {
                $last_transaction_id = self::get_latest_transaction_id_by_branch($branch['branch_id']);
            } else {
                $last_transaction_id = $transaction_id;
            }            
            //Calling a process to save transactions
            $params = array(
                'ultimo' => $last_transaction_id,
                'cantidad' => 2,
                'sede' => $branch['branch_id'],
            );
            MigrationCustom::process_transactions($params, $web_service_params);
        }
    }   

    /**
     * Loads a specific set of transactions from the transactions table and executes them
     * @param array Transactions filter
     * @param int Optional limit of transactions to execute
     * @return void
     */
    function load_transactions() {               
        //Getting transactions of the migration_transaction table
        $branches = self::get_branches();
        
        if (!empty($branches)) {
            foreach ($branches as $branch_info) {
                //Get uncompleted transactions                
                $transactions = self::get_transactions(0, $branch_info['branch_id']);                
         
                $options = array('where' => array('branch_id = ? and status_id <> ?' => array($branch_info['branch_id'], 0)), 'order' => 'id desc', 'limit' => '1');
                $transaction_info = self::get_transaction_by_params($options, 'first');   
                
                $latest_id_attempt = 1;
                if ($transaction_info) {                
                    $latest_id = $transaction_info['id'];                
                    $latest_id_attempt = $latest_id + 1;
                }
                    
                $item = 1;//counter
                if (!empty($transactions)) {
                    $count = count($transactions);
                    error_log("\nTransactions found: $count");

                    //Looping transactions
                    foreach ($transactions as $transaction) {
                        
                        //Calculating percentage
                        $percentage = $item / $count * 100;
                        if (round($percentage) % 10 == 0) {
                            $percentage = round($percentage, 3);
                            error_log("\nProcessing transaction #{$transaction['id']} $percentage%");
                        }
                        $item++;
                        //--
                        error_log("\nend transaction ->  \n");
                        error_log("Waiting for transaction #$latest_id_attempt ...");
                        
                        //Checking "huecos"
                        //Waiting transaction is fine continue:
                        if ($transaction['id'] == $latest_id_attempt) {
                            $latest_id_attempt++;
                        } else {
                            error_log("Transaction #$latest_id_attempt is missing in branch #{$branch_info['branch_id']}");
                            exit;
                        }
                        
                        self::execute_transaction($transaction_info);
                        
                    }
                } else {
                    error_log('No transactions to load');
                }
            }
        } else {
            error_log('No branches found');
        }

        $actions = array(); //load actions from Mysql
        foreach ($actions as $action_data) {
            if (in_array($action_data['action'], $transactions)) {
                $function_to_call = $transactions[$action_data['action']];
                $function_to_call($action_data['params']);
            }
        }
    }
    
    function execute_transaction($transaction_info) {
        //Loading function. The action is now numeric, so we call a transaction_1() function, for example
        $function_to_call = "transaction_" . $transaction_info['action'];
        if (method_exists('MigrationCustom', $function_to_call)) {
            error_log("\n-----------------------------------------------------------------------");
            error_log("\nCalling function MigrationCustom::$function_to_call");

            $result = MigrationCustom::$function_to_call($transaction_info, $this->web_service_connection_info);
            $result['message'] = "Funcion called: MigrationCustom::$function_to_call()  \n Function reponse: ".$result['message'];            
            error_log('Reponse: '.$result['message']);          
            if (!empty($transaction_info['id'])) {
                self::update_transaction(array('id' => $transaction_info['id'] , 'status_id' => $result['status_id']));                  
            } else {
                error_log("Cant update transaction, id was not provided");                
            }
            return $result;
        } else {
            //	method does not exist
            $error_message = "Function $function_to_call does not exists";
            error_log($error_message);
            
            //Failed
            if (!empty($transaction_info['id'])) {
                self::update_transaction(array('id' => $transaction_info['id'] , 'status_id' => MigrationCustom::TRANSACTION_STATUS_FAILED));
            }
            return array('message' => $error_message);
        }
    }
    
    /** 
     * 
     * @param int Transaction id of the third party 
     * 
     */
    function load_transaction_by_third_party_id($transaction_external_id, $branch_id, $forced = false) {        
        //Asking for 2 transactions by getting 1
        
        $params = array(
            'ultimo' => $transaction_external_id, 
            'cantidad' => 2,
            'sede' => $branch_id
        );
        
        $result = self::soap_call($this->web_service_connection_info, 'transacciones', $params);
        
        //Hacking webservice default result        
        if ($result && isset($result[0])) {
            //Getting 1 transaction
            $result = $result[0];            
            
            $transaction_external_id++;            
            if ($result['idt'] == $transaction_external_id) {
                $message = Display::return_message('Transaction id found in third party', 'info');
                
                //Adding third party transaction to Chamilo
                $transaction_result = MigrationCustom::process_transaction($result, null, $forced);
                
                $transaction_chamilo_info = array();
                
                if ($transaction_result['error'] == false) {
                    $chamilo_transaction_id = $transaction_result['id'];
                    $message .= Display::return_message($transaction_result['message'], 'info');
                    $transaction_chamilo_info = self::get_transaction_by_params(array('Where' => array('id = ?' => $chamilo_transaction_id), 'first'));
                    if (isset($transaction_chamilo_info) && isset($transaction_chamilo_info[$chamilo_transaction_id])) {
                        $transaction_chamilo_info = $transaction_chamilo_info[$chamilo_transaction_id];
                    } else {
                        $transaction_chamilo_info = null;
                    }                    
                } else {
                    $message .= Display::return_message("Transaction NOT added to Chamilo. {$transaction_result['message']}", 'warning');
                    //$transaction_chamilo_info = MigrationCustom::process_transaction($result, false);                    
                }
                
                if (!empty($transaction_chamilo_info)) {
                    $transaction_result = $this->execute_transaction($transaction_chamilo_info);
                    if ($transaction_result) {
                        $message .= Display::page_subheader("Transaction result:");
                        $message .= nl2br($transaction_result['message']);
                        $message .= "<br />";
                        
                        if (isset($transaction_result['entity']) && !empty($transaction_result['entity'])) {                        
                            $message .= Display::page_subheader2("Entity {$transaction_result['entity']} before:");
                            $message .= "<pre>".print_r($transaction_result['before'], 1)."</pre>";
                            $message .= "<br />";

                            $message .= Display::page_subheader2("Entity {$transaction_result['entity']} after:");
                            $message .= "<pre>".print_r($transaction_result['after'], 1)."</pre>";
                            $message .= "<br />";
                        }                        
                    } else {
                        $message .= Display::return_message("Transaction failed", 'error');                        
                    }
                }                
                
                return array(
                    'message' => $message,
                    'raw_reponse' => 
                                    //Display::page_subheader2("Transactions:"). 
                                     Display::page_subheader3("Chamilo transaction info:")."<pre>".print_r($transaction_chamilo_info, true)."</pre>".
                                     Display::page_subheader3("Webservice transaction reponse:")."<pre>".print_r($result, true)."</pre>",
                );
            }
        }
        
        return array(
            'message' => Display::return_message("Transaction NOT found in third party", 'warning'),
            //'raw_reponse' => print_r($result, true)
        );
    }
    
    /**
     * Prepares the relationship between two fields (one from the original database and on from the destination/local database)
     * @param array List of fields that must be matched ('fields_match' => array(0=>array('orig'=>'...','dest'=>'...',...)))
     * @return mixed Modified field
     */
    function prepare_field_match($table) {
        $sql_select_fields = array();
        if (!empty($table['fields_match'])) {
            foreach ($table['fields_match'] as $details) {
                if (empty($details['orig'])) {
                    //Ignore if the field declared in $matches doesn't exist in
                    // the original database
                    continue;
                }
                $sql_select_fields[$details['orig']] = $details['orig'];
                // If there is something to alter in the SQL query, rewrite the entry
                if (!empty($details['sql_alter'])) {
                    $func_alter = $details['sql_alter'];
                    $sql_select_fields[$details['orig']] = MigrationCustom::$func_alter($details['orig']);
                }
                //error_log('Found field ' . $details['orig'] . ' to be selected as ' . $sql_select_fields[$details['orig']]);
            }
        }
        return $sql_select_fields;
    }
    /**
     * Executes a fields match
     * @param array List of fields that must be matched ('fields_match' => array(0=>array('orig'=>'...','dest'=>'...',...)))
     * @param array Row of data
     * @param array Extra fields table definition
     */
    function execute_field_match($table, $row, $extra_fields = array()) {
        //error_log('execute_field_match');
        $dest_row = array();
        $first_field = '';
        // If a dest table exists, fill $my_extra_fields
        $my_extra_fields = isset($table['dest_table']) && isset($extra_fields[$table['dest_table']]) ? $extra_fields[$table['dest_table']] : null;
        $extra_field_obj = null;
        $extra_field_value_obj = null;
        if (!empty($table['dest_table'])) {
            $extra_field_obj = new Extrafield($table['dest_table']);
            $extra_field_value_obj = new ExtraFieldValue($table['dest_table']);
        }
        $extra_fields_to_insert = array();
        
        foreach ($table['fields_match'] as $id_field => $details) {
            //if ($table['dest_table'] == 'session') {error_log('Processing field '.$details['orig']);}
            $params = array();
            // Remove the table name prefix if any (in the orig field)
            if (isset($details['orig'])) {
                $field_exploded = explode('.', $details['orig']);
                if (isset($field_exploded[1])) {
                    $details['orig'] = $field_exploded[1];
                }
            }

            // process the fields one by one
            if ($details['func'] == 'none' || empty($details['func'])) {
                // if no function is defined to alter the field, take it as is
                $dest_data = $row[$details['orig']];
            } else {
                // if an alteration function is defined, run it on the field
                //error_log(__FILE__.' '.__LINE__.' Preparing to treat field with '.$details['func']);
                $dest_data = MigrationCustom::$details['func']($row[$details['orig']], $this->data_list, $row);
            }

            if (isset($dest_row[$details['dest']])) {
                $dest_row[$details['dest']] .= ' ' . $dest_data;
            } else {
                $dest_row[$details['dest']] = $dest_data;
            }

            //Extra field values
            $extra_field = isset($my_extra_fields) && isset($my_extra_fields[$details['dest']]) ? $my_extra_fields[$details['dest']] : null;
            // Check the array is there
            //if($table['dest_table'] == 'session') error_log('Fucking Extra field: '.print_r($extra_field,1));
            if (!empty($extra_field) && $extra_field_obj) {
                //if($table['dest_table'] == 'session') error_log('Extra_field no es vacío');
                // Check the "options" array is defined for this field (checking is_array is crucial here, see BT#5215)
                if (is_array($extra_field['options']) && count($extra_field['options'])>0) {
                    //if($table['dest_table'] == 'session') error_log('...y sus opciones son: '.print_r($extra_field['options'],1));
                    //if($details['orig']=='uidIdPrograma') { error_log('Eso era lo inicial, del cual se tomó '.$details['dest'].': '.print_r($my_extra_fields,1));}
                    $options = $extra_field['options'];
                    $field_type = $extra_field['field_type'];
                    //if ($table['dest_table'] == 'session') {error_log('Field orid: '.$details['orig']);}
                    
                    if (!empty($options)) {
                        //if ($table['dest_table'] == 'session') {error_log('Options not empty');}
                        if (!is_array($options)) { $options = array($options); }
                        foreach ($options as $option) {
                            if (is_array($option)) {
                              foreach ($option as $key => $value) {
                                //error_log("$key $value --> {$dest_row[$details['dest']]} ");
                                if ($key == 'option_value' && $value == $dest_row[$details['dest']]) {
                                    $value = $option['option_display_text'];
                                    if ($field_type == Extrafield::FIELD_TYPE_SELECT) {
                                        $value = $option['option_value'];
                                    }
                                    $params = array(
                                        'field_id' => $option['field_id'],
                                        'field_value' => $value,
                                    );
                                    break(2);
                                }
                            }
                          }
                        }
                    }
                } else {
                    $params = array(
                        'field_id' => $extra_field,
                        'field_value' => $dest_row[$details['dest']],
                    );
                }
                if (!empty($params)) {
                    $extra_fields_to_insert[] = $params;
                }
                unset($dest_row[$details['dest']]);
            }
            unset($extra_field);
        }
        //if ($table['dest_table']=='session') { error_log('Params: '.print_r($params,1)); }
        // If a dest_func entry has been defind, use this entry as the main 
        // operation to execute when inserting the item
        if (!empty($table['dest_func'])) {
            //error_log('Calling '.$table['dest_func'].' on data recovered: '.print_r($dest_row, 1));            
            $dest_row['return_item_if_already_exists'] = true;

            $item_result = call_user_func_array($table['dest_func'], array($dest_row, $this->data_list));

/*            if (isset($table['show_in_error_log']) && $table['show_in_error_log'] == false) {
                
            } else {
                //error_log('Result of calling ' . $table['dest_func'] . ': ' . print_r($item_result, 1));
            }
*/
            //error_log('Result of calling ' . $table['dest_func'] . ': ' . print_r($item_result, 1));
            //After the function was executed fill the $this->data_list array
            switch ($table['dest_table']) {
                case 'course':
                    //Saving courses in array
                    if ($item_result) {
                        //$this->data_list['courses'][$dest_row['uidIdCurso']] = $item_result;        
                    } else {
                        error_log('Course Not FOUND');
                        error_log(print_r($item_result, 1));
                        exit;
                    }
                    $handler_id = $item_result['code'];
                    break;
                case 'user':
                    if (!empty($item_result)) {
                        $handler_id = $item_result['user_id'];
                        //error_log($dest_row['email'].' '.$dest_row['uidIdPersona']);
                        if (isset($dest_row['uidIdAlumno'])) {
                            //$this->data_list['users_alumno'][$dest_row['uidIdAlumno']]['extra'] = $item_result;
                        }
                        if (isset($dest_row['uidIdEmpleado'])) {
                            //print_r($dest_row['uidIdEmpleado']);exit;                           
                            //$this->data_list['users_empleado'][$dest_row['uidIdEmpleado']]['extra'] = $item_result;
                        }
                    } else {
                        global $api_failureList;
                        error_log('Empty user details');
                        error_log(print_r($api_failureList, 1));
                    }
                    break;
                case 'session':
                    //$this->data_list['sessions'][$dest_row['uidIdPrograma']] = $item_result;                    
                    $handler_id = $item_result; //session_id
                    break;
            }

            //Saving extra fields of the element
            //error_log('Checking extra fields for '.$extra_field_value_obj->handler_id.' '.$handler_id);
            if (!empty($extra_fields_to_insert)) {
                foreach ($extra_fields_to_insert as $params) {
                    //error_log('Trying to save '.print_r($params,1));
                    $params[$extra_field_value_obj->handler_id] = $handler_id;
                    $extra_field_value_obj->save($params);
                }
            }
        } else {
            // $this->errors_stack[] = "No destination data dest_func found. Abandoning data with first field $first_field = " . $dest_row[$first_field];
        }
        unset($extra_fields_to_insert); //remove to free up memory
        return $dest_row;
    }

    /**
     * Helper function to create extra fields in the Chamilo database. If the 
     * extra field aleady exists, then just return the ID of this field. If 
     * options are provided ('options' sub-array), then options are inserted in
     * the corresponding x_field_options table.
     * @param Array An array containing an 'extra_fields' entry with details about the required extra fields
     * @return void
     */
    private function _create_extra_fields(&$table) {
        $extra_fields = array();

        error_log('Inserting (if not exist) extra fields for : ' . $table['dest_table'] . " \n");
        foreach ($table['extra_fields'] as $extra_field) {
            //error_log('Preparing for insertion of extra field ' . $extra_field['field_display_text'] . "\n");
            $options = isset($extra_field['options']) ? $extra_field['options'] : null;
            unset($extra_field['options']);

            $extra_field_obj = new ExtraField($table['dest_table']);
            $extra_field_id = $extra_field_obj->save($extra_field);

            $selected_fields = self::prepare_field_match($options);

            //Adding options. This is only processed if the corresponding 
            // extra_field has an 'options' sub-aray defined
            if (!empty($options)) {
                $extra_field_option_obj = new ExtraFieldOption($table['dest_table']);
                // use the query defined in the 'query' item as returned in a select by prepare_field_match above
                $this->select_all($options['orig_table'], $selected_fields);
                $num_rows = $this->num_rows();

                if ($num_rows) {
                    $data_to_insert = array();
                    $data_to_insert['field_id'] = $extra_field_id;

                    while ($row = $this->fetch_array()) {
                        $data = self::execute_field_match($options, $row);
                        $data_to_insert = array_merge($data_to_insert, $data);
                        $extra_field_option_obj->save_one_item($data_to_insert, false, false);
                        //error_log(print_r($extra_fields[$table['dest_table']]['extra_field_'.$extra_field['field_variable']], 1));
                        $extra_fields[$table['dest_table']]['extra_field_' . $extra_field['field_variable']]['options'][] = $data_to_insert;
                        $extra_fields[$table['dest_table']]['extra_field_' . $extra_field['field_variable']]['field_type'] = $extra_field['field_type'];
                    }
                    //$extra_fields[$table['dest_table']]['extra_field_'.$extra_field['field_variable']]['selected_option'] = 
                    //error_log('$data: ' . print_r($data_to_insert, 1));
                }
            } else {
                // if there are no pre-defined options, then just return the field_id for this variable
                $extra_fields[$table['dest_table']]['extra_field_' . $extra_field['field_variable']] = $extra_field_id;
            }
        }
        return $extra_fields;
    }
}
