<?php

if (php_sapi_name() != 'cli') {
	die('Must run from command line');
}

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 0);
ini_set('html_errors', 0);

require 'php-cli-tools/lib/cli/cli.php';
\cli\register_autoload();

require 'php-csv-utils-0.3/csv.php';
\csv\register_autoload();

require 'whistle-sdk/User.php';
require 'whistle-sdk/Device.php';
require 'whistle-sdk/Voicemail_box.php';
require 'whistle-sdk/Call_flow.php';
require 'whistle-sdk/Auth.php';

$csv = array();
$csv_headers = array();

$csv_mapping = array();
$defaults = array();

$settings = array(
    'Crossbar URL' => 'http://your.domain.com:8000/v1/',
    'Crossbar Username' => 'yourusername',
    'Crossbar Password' => 'yourpassword',
    'Crossbar Realm' => 'your.customer.realm.com',
    'Account ID' => '',
    'CSV Path' => __DIR__ . DIRECTORY_SEPARATOR .'users.csv',
    'Config Path' => __DIR__ . DIRECTORY_SEPARATOR .'config.xbi'
);

$status = read_csv($settings, &$csv, &$csv_headers);
while(true) {
    print_header('Main menu', $status);
    
    $menu = array(
        'configure' => 'Configure',
        'read_csv' => 'Re-read CSV',
        'set_defaults' => 'Set property defaults',
        'map_csv' => 'Map CSV fields to properties',      
        'commit' => 'Commit!',
        'quit' => 'Quit'
    );

    $choice = \cli\menu($menu, null, 'Choose an action');
    \cli\line();

    switch ($choice) {
        case 'quit':
            system("clear");
            \cli\line();
            break 2;
        case 'configure':
            $status = configuration_menu($settings, $csv_mapping, $defaults);
            break;
        case 'read_csv':
            $status = read_csv($settings, &$csv, &$csv_headers);
            break;
        case 'set_defaults':
            $status = set_default_properties($defaults);
            break;        
        case 'map_csv':
            $status = map_csv($csv_headers, $csv_mapping);
            break;
        case 'commit':
            commit($csv_mapping, $defaults, $settings, $csv, $csv_headers);
            break;
    }         
}

function commit($csv_mapping, $defaults, $settings, $csv, $csv_headers) {
    unset($csv[0]);

    $auth = new Auth($settings['Crossbar URL'], $settings['Crossbar Realm']
                     ,$settings['Crossbar Username'], $settings['Crossbar Password']);
    $auth->setUserAuth();

    $defaultData = array();

    foreach ($defaults as $key => $value) {
        set($key, $value, $defaultData);
    }
    
    $existing = array();
    
    foreach($csv as $idx => $row) {
        $data = $defaultData;
                
        try {
            foreach($csv_mapping as $csv_header => $mappings) {
                foreach ($mappings as $idx => $mapping) {
                    $headers = array_flip($csv_headers);
                    $column = $headers[$csv_header];
                    set($mapping, $row[$column], $data);
                }
            }

            prepare_user($data, $settings);

            commit_object(new User($settings['Crossbar URL']), &$data);

            prepare_device($data, $settings);     

            commit_object(new Devices($settings['Crossbar URL']), &$data);

            prepare_voicemail($data, $settings);

            commit_object(new Voicemail_box($settings['Crossbar URL']), &$data);

            prepare_callflow($data, $settings);

            commit_object(new Call_flow($settings['Crossbar URL']), &$data);
        } catch (Exception $e) { }        
        \cli\line();    
    }
    
    return "CSV import complete!";
}

function commit_object($object, &$data) {
    $key = get_class($object);
    
    if (!isset($existing[$key])) {
        $result = $object->getAll();
        if ($result['status'] == 'success') {
            $existing[$key] = $result['data'];
        }            
    }
    
    if (is_array($existing[$key])) {
        foreach ($existing[$key] as $idx => $summary) {
            $is_match = false;
            switch ($key) {
                case 'User':
                    if ($data[$key]['first_name'] ==  $summary['first_name'] && $data[$key]['last_name'] ==  $summary['last_name']) {
                       $is_match = true; 
                    };
                    break;
                case 'Voicemail_box':
                case 'Devices':
                    if ($data[$key]['name'] ==  $summary['name']) {
                       $is_match = true; 
                    };
                    break;
                case 'Call_flow':
                    if ($data[$key]['numbers'] ==  $summary['numbers']) {
                       $is_match = true; 
                    };
                    break;                    
            }
            if ($is_match) {
                $result = $object->get($summary['id']);
                if ($result['status'] == 'success') {
                    $data[$key] = array_replace_recursive($data[$key], $result['data']);
                }
            }
        }            
    }
    
    $update = false;
    if (!empty($data[$key]['id'])) {
        $update = true;
        $result = $object->update($data[$key]['id'], $data[$key]);
    } else {
        $result = $object->add($data[$key]);            
    }

    if ($result['status'] != 'success') {
        \cli\line("ERROR: could not create/update $key:" .print_r($result, true));
        \cli\line();
        throw new Exception("could not create/update $key");
    }

    $data[$key] = array_replace_recursive($data[$key], $result['data']);    
    
    $id = $data[$key]['id'];
    if ($update) {
        \cli\line("Updated $key: $id");        
    } else {
        \cli\line("Created $key: $id");
    }
}

/**
 * configuration sub menu
 */
function configuration_menu(&$settings, &$csv_mapping, &$defaults, $status = null) {
    print_header('Configure', $status);
    
    $menu = array(
        'configure' => 'Configure',
        'import_config' => 'Import Config',
        'export_config' => 'Export Config',            
        'return' => 'Return'
    );

    $choice = \cli\menu($menu, null, 'Choose an action');
    \cli\line();

    switch ($choice) {
        case 'return':
            return false;
        case 'configure':
            $status = configure($settings);
            break;
        case 'set_defaults':
            $status = set_default_properties($defaults);
            break;
        case 'export_config':
            $status = export_config($settings, $csv_mapping, $defaults);
            break;
        case 'import_config':
            $status = import_config($settings, $csv_mapping, $defaults);
            break;
    }  
    
    configuration_menu(&$settings, &$csv_mapping, &$defaults, $status);
}

/**
 * read cvs function
 */
function read_csv($settings, &$csv, &$csv_headers){
    $file = $settings['CSV Path'];
    try {
        $csv = new Csv_Reader($file);
        $csv = $csv->toArray();
        $csv_headers = $csv[0];
        return "Loaded CSV from: $file";     
    } catch (Exception $e) {
        $csv = $csv_headers = array();
        return "Failed to load CSV:" .$e->getMessage();        
    }
}

/**
 * map csv fields
 */
function map_csv($csv_headers, &$csv_mapping, $status = null) {
    print_header('Map CSV fields to properties', $status);
    
    $table_headers = array('CSV Column', 'CSV Name', 'Mapped To');
    $table_rows = array();
    foreach ($csv_headers as $pos => $name) {
        $mapping = '';
        if(!empty($csv_mapping[$name]))
            $mapping = implode(";", $csv_mapping[$name]);
        
        $table_rows[] = array($pos, $name, $mapping);
    }

    $table = new \cli\Table();
    $table->setHeaders($table_headers);
    $table->setRows($table_rows);
    $table->display();
    
    do{
        $csv_column_num = \cli\prompt("Choose a CSV column (type 'r' to return)");
        if ($csv_column_num === 'r') {
            return null;
        }
    } while(!isset($csv_headers[$csv_column_num]));
 
    $csv_column_name = $csv_headers[$csv_column_num];
    
    $status = map_csv_column_properties($csv_column_name, $csv_mapping);
    map_csv($csv_headers, $csv_mapping, $status);
}

/**
 * set defualt properties
 */
function set_default_properties(&$defaults, $status = null) {
    print_header('Set property defaults', $status);
    
    $table_headers = array('Property Name', 'Value');
    $table_rows = array();
    foreach ($defaults as $name => $value) {
        $table_rows[] = array($name, $value);
    }

    $table = new \cli\Table();
    $table->setHeaders($table_headers);
    $table->setRows($table_rows);
    $table->display();

    $menu = array(
        'add' => 'Add default property',
        'remove' => 'Remove default property',
        'clear' => 'Clear all',
        'return' => 'Return'
    );

    $choice = \cli\menu($menu, null, 'Choose an action');
    \cli\line();

    switch ($choice) {
        case 'return':
            return false;
        case 'add':
            if (($update = add_default_property())) {
                $default = !empty($defaults[$update]) ? $defaults[$update] : null;
                $defaults[$update] = \cli\prompt("Enter new value for $update", $default);
                $status = "Added default property '$update'";
            }
            break;
        case 'remove':
            if (($update = remove_default_property($defaults))) {
                unset($defaults[$update]);
                $status = "Removed default property '$update'";
            }
            break;
        case 'clear':
            $defaults = array();
            $status = "Cleared all default properties";
            break;
    }        
    
    set_default_properties($defaults, $status);
}

/**
 * common functions
 */
function print_header($menu = null, $status = false) {
    system("clear");
    \cli\line("__   ______               _____                            _            ");
    \cli\line("\ \ / /  _ \             |_   _|                          | |           ");
    \cli\line(" \ V /| |_) | __ _ _ __    | |  _ __ ___  _ __   ___  _ __| |_ ___ _ __ ");
    \cli\line("  > < |  _ < / _` | '__|   | | | '_ ` _ \| '_ \ / _ \| '__| __/ _ \ '__|");
    \cli\line(" / . \| |_) | (_| | |     _| |_| | | | | | |_) | (_) | |  | ||  __/ |   ");
    \cli\line("/_/ \_\____/ \__,_|_|    |_____|_| |_| |_| .__/ \___/|_|   \__\___|_|   ");
    \cli\line("                                         | |                            ");
    \cli\line("                                         |_|                            ");    
    \cli\line();
    if ($status) {
        \cli\line($status);
        \cli\line();
    }
    \cli\line($menu);
    
}

function choose_property($class, $mapping = false, $schema = false) {
    if (!$schema) {
        $schema = $class->get_schema();
    }
    
    if (!$mapping) {
        $mapping = get_class($class);
    }
    
    $properties = array_keys($schema);
    
    $properties['return'] = "Return";
    
    $choice = \cli\menu($properties, null, 'Choose an property to add');
    
    if(!isset($properties[$choice])) {
        choose_property($class, $mapping, $schema);
    } else if ($choice === 'return') {
        return false;
    }
    
    $key = $properties[$choice];
    
    if (is_array($schema[$key])) {
        return choose_property($class, $mapping .'.' .$key, $schema[$key]);
    }

    return $mapping .'.' .$key; 
}

function set($key, $value, &$data) {
    if (empty($value)) {
        return false;
    }
    
    $position = &$data;
    foreach(explode('.', $key) as $idx => $subkey) {
        $position = &$position[$subkey];
    }
    
    $position = $value;
}

/**
 * configuration sub menu functions
 */
function configure(&$settings) {
    print_header('Alter Configuration');
    
    $table_headers = array('Setting', 'Name', 'Value');
    
    $table_rows = array();
    foreach ($settings as $Name => $value) {
        $table_rows[] = array(count($table_rows), $Name, $value);
    }
    
    $table = new \cli\Table();
    $table->setHeaders($table_headers);
    $table->setRows($table_rows);
    $table->display();    

    do{
        $setting = \cli\prompt("Choose a setting to alter (type 'r' to return)");
        if ($setting === 'r') {
            return null;
        }
    } while(!is_numeric($setting) || $setting > count($settings) - 1 || $setting < 0);

    $keys = array_keys($settings);
    $key = $keys[$setting];
    $value = $settings[$key];
    
    $settings[$key] = \cli\prompt("Enter new value for $key", $value);
 
    configure($settings);
}

function export_config(&$settings, &$csv_mapping, &$defaults) {
    $file = $settings['Config Path'];
    try {
        $export = array(
                    'settings' => $settings,
                    'csv_mapping' => $csv_mapping,
                    'defaults' => $defaults
                );
        file_put_contents($file, serialize($export));
        return "Exported configuration to: $file";
    } catch (Exception $e) {
        return "Failed to import configuration:" .$e->getMessage();        
    }    
}

function import_config(&$settings, &$csv_mapping, &$defaults) {
    $file = $settings['Config Path'];
    try {
        $contents = file_get_contents($file);
        $imported = unserialize($contents);
        $settings = $imported['settings'];
        $csv_mapping = $imported['csv_mapping'];
        $defaults = $imported['defaults'];
        return "Imported configuration from: $file";
    } catch (Exception $e) {
        return "Failed to import configuration:" .$e->getMessage();
    }
}

/**
 * csv mapping helper functions
 */
function map_csv_column_properties($csv_column_name, &$csv_mapping) {
    print_header('Alter CSV to properties mappings');

    $menu = array(
        'add' => 'Add property',
        'remove' => 'Remove property',
        'return' => 'Return'
    );

    $choice = \cli\menu($menu, null, 'Choose an action');
    \cli\line();

    switch ($choice) {
        case 'return':
            return false;
        case 'add':
            if (($update = add_csv_column_property($csv_column_name))) {
                $csv_mapping[$csv_column_name][] = $update;
                return "Added property '$update' to field '$csv_column_name'";
            }
            break;
        case 'remove':
            $update = remove_csv_column_property($csv_mapping[$csv_column_name], $csv_column_name);
            if (isset($csv_mapping[$csv_column_name][$update])) {
                unset($csv_mapping[$csv_column_name][$update]);
                return "Removed property from field '$csv_column_name'";
            }
            break;
    }        
}

function add_csv_column_property($csv_column_name) {
    print_header("Add property to CSV field '$csv_column_name'");
    
    $menu = array(
	'user' => 'User',
	'device' => 'Device',
	'voicemail' => 'Voicemail',
        'callflow' => 'Callflow',
	'return' => 'Return',
    );    
    
    $choice = \cli\menu($menu, null, 'Choose an object');
    \cli\line();
    
    switch ($choice) {
        case 'return':
            return false;
        case 'user':
            return choose_property(new User());
            break;
        case 'device':
            return choose_property(new Devices());    
            break;
        case 'voicemail':
            return choose_property(new Voicemail_box());
            break;           
        case 'callflow':
            return choose_property(new Call_flow());
            break;          
    }      
}

function remove_csv_column_property($properties, $csv_column_name) {
    print_header("Remove property from CSV field '$csv_column_name'");
    
    $properties['return'] = "Return";
    
    $choice = \cli\menu($properties, null, 'Choose a property to remove');
    \cli\line();
    
    if (!isset($properties[$choice])) {
        return remove_csv_column_property($properties);
    } else if ($choice === 'return') {
        return false;
    }
    
    return $choice;
}

/**
 * set defaults helper functions
 */
function remove_default_property($defaults) {
    $keys = array_keys($defaults);
    $keys['return'] = "Return";
    $update = \cli\menu($keys, null, 'Choose a property');
    if (isset($keys[$update])) {
        return $keys[$update];
    }    
    return false;
}

function add_default_property() {
    $menu = array(
	'user' => 'User',
	'device' => 'Device',
	'voicemail' => 'Voicemail',
	'return' => 'Return',
    );    
    
    $choice = \cli\menu($menu, null, 'Choose an object');
    \cli\line();
    
    switch ($choice) {
        case 'return':
            return false;
        case 'user':
            return choose_property(new User());
            break;
        case 'device':
            return choose_property(new Devices());    
            break;
        case 'voicemail':
            return choose_property(new Voicemail_box());
            break;                      
    } 
}

function prepare_user(&$data, $settings) {
    $user = &$data['User'];
    
    if (!isset($user['username'])) {
        $username = false;
        if (!empty($user['email'])) {
            $username = $user['email'];
        } else if(!empty($user['first_name']) && !empty($user['last_name'])) {                
            $username = str_replace(' ', '', $user['first_name']) 
                        .str_replace(' ', '', $user['last_name']);
            $username = strtolower($username);
        }

        if (!empty($username)) {
            $user['username'] = $username;
        }
    }

    if (!isset($user['timezone'])) {
        $user['timezone'] = "America/Los_Angeles";
    }

    if (isset($user['verified'])) {
        $user['verified'] = (boolean)$user['verified'];
    }

    if (!isset($user['vm_to_email_enabled'])) {
        $user['vm_to_email_enabled'] = !empty($user['email']);
    } else {
        $user['vm_to_email_enabled'] = (boolean)$user['vm_to_email_enabled'];
    }

    if (isset($user['call_forward']['enabled'])) {
        $user['call_forward']['enabled'] = (boolean)$user['call_forward']['enabled'];
    } else {
        $user['call_forward']['enabled'] = false;
    }

    if (isset($user['call_forward']['require_keypress'])) {
        $user['call_forward']['require_keypress'] = (boolean)$user['call_forward']['require_keypress'];
    } else {
        $user['call_forward']['require_keypress'] = true;
    }

    if (isset($user['call_forward']['keep_caller_id'])) {
        $user['call_forward']['keep_caller_id'] = (boolean)$user['call_forward']['keep_caller_id'];
    } else {
        $user['call_forward']['keep_caller_id'] = true;
    }

    if (empty($user['priv_level']) || $user['priv_level'] != 'admin') {
        $user['priv_level'] = 'user';
    }

    if (empty($user['apps']['userportal']['api_url'])) {
        $user['apps']['userportal']['api_url'] = $settings['Crossbar URL'];
    }

    if (empty($user['apps']['userportal']['icon'])) {
        $user['apps']['userportal']['icon'] = "userportal";
    }

    if (empty($user['apps']['userportal']['label'])) {
        $user['apps']['userportal']['label'] = "User Portal";
    }

    if ($user['priv_level'] == 'admin') {
        if (empty($user['apps']['voip']['api_url'])) {
            $user['apps']['voip']['api_url'] = $settings['Crossbar URL'];
        }

        if (empty($user['apps']['voip']['icon'])) {
            $user['apps']['voip']['icon'] = "voip_services";
        }

        if (empty($user['apps']['voip']['label'])) {
            $user['apps']['voip']['label'] = "Voip Services";
        }
    }    
}

function prepare_device(&$data, $settings) {
    $device = &$data['Devices'];
    $user = $data['User'];

    if (!isset($device['name']) && !empty($user['first_name'])) {
        $device['name'] = $user['first_name'] .'\'s Device'; 
    }
    
    if (!isset($device['device_type'])) {
        $device['device_type'] = 'sip_device'; 
    }    

    if (!isset($device['owner_id'])) {
        $device['owner_id'] = $user['id']; 
    }    
    
    if (empty($device['sip']['method']) && $device['device_type'] == 'sip_device') {
        $device['sip']['method'] = 'password'; 
    }        

    if (empty($device['sip']['invite_format']) && $device['device_type'] == 'sip_device') {
        $device['sip']['invite_format'] = 'username'; 
    }        

    if (empty($device['sip']['realm']) && $device['device_type'] == 'sip_device') {
        $device['sip']['realm'] = $settings['Crossbar Realm']; 
    } 
    
    if (empty($device['sip']['username']) && $device['device_type'] == 'sip_device') {
        $device['sip']['username'] = 'device_' .generatePassword(6); 
    }   
    
    if (empty($device['sip']['password']) && $device['device_type'] == 'sip_device') {
        $device['sip']['password'] = generatePassword(10); 
    }   
    
    if (empty($device['sip']['expire_seconds']) && $device['device_type'] == 'sip_device') {
        $device['sip']['expire_seconds'] = '360'; 
    }
    
    if (!isset($device['media']['bypass_media'])) {
        $device['media']['bypass_media'] = false; 
    } else {
        $device['media']['bypass_media'] = (boolean)$device['media']['bypass_media'];
    }
    
    if (!isset($device['media']['audio']['codecs'])) {
        $device['media']['audio']['codecs'] = array('PCMU', 'PCMA'); 
    } else if (is_string($device['media']['audio']['codecs'])) {
        $device['media']['audio']['codecs'] = explode(',', $device['media']['audio']['codecs']);
    }
    
    if (!isset($device['media']['video']['codecs'])) {
        $device['media']['video']['codecs'] = array(); 
    } else if (is_string($device['media']['video']['codecs'])) {
        $device['media']['video']['codecs'] = explode(',', $device['media']['video']['codecs']);
    }    

    if (!isset($device['media']['fax']['option'])) {
        $device['media']['fax']['option'] = 'auto'; 
    }    
}

function prepare_voicemail(&$data, $settings) {
    $vmbox = &$data['Voicemail_box'];
    $user = $data['User'];   
    
    if (!isset($vmbox['name']) && !empty($user['first_name'])) {
        $vmbox['name'] = $user['first_name'] .'\'s Voicemail'; 
    }    
    
    if (!isset($vmbox['mailbox'])) {
        $vmbox['mailbox'] = reset($data['Call_flow']['numbers']);
    }    
    
    if (!isset($vmbox['pin'])) {
        $vmbox['pin'] = generatePassword(4, true);
    }    
    
    if (!isset($vmbox['require_pin'])) {
        $vmbox['require_pin'] = true;
    } else {
        $vmbox['require_pin'] = (boolean)$vmbox['require_pin'];
    }

    if (!isset($vmbox['check_if_owner'])) {
        $vmbox['check_if_owner'] = true;
    } else {
        $vmbox['check_if_owner'] = (boolean)$vmbox['check_if_owner'];
    }    
    
    if (!isset($vmbox['skip_greeting'])) {
        $vmbox['skip_greeting'] = false;
    } else {
        $vmbox['skip_greeting'] = (boolean)$vmbox['skip_greeting'];
    }       
    
    if (!isset($vmbox['skip_instructions'])) {
        $vmbox['skip_instructions'] = false;
    } else {
        $vmbox['skip_instructions'] = (boolean)$vmbox['skip_instructions'];
    }    
}

function prepare_callflow(&$data, $settings) {
    $callflow = &$data['Call_flow'];

    if (!isset($callflow['numbers'])) {
        $callflow['numbers'] = 'change_me'; 
    } else if (is_string($callflow['numbers'])) {
        $callflow['numbers'] = explode(',', $callflow['numbers']);
    }
    
    $callflow['flow'] = array (
                            'module' => 'device',
                            'data' => array('id' => $data['Devices']['id']),
                            'children' => array('_' => array (
                                'module' => 'voicemail',
                                'data' => array('id' => $data['Voicemail_box']['id']),
                                'children' => array()
                            ))
                        );
}

function generatePassword($length=9, $numbers_only=false) {
	$vowels = 'aeuyAEUY';
	$consonants = 'bdghjmnpqrstvzBDGHJLMNPQRSTVWXZ23456789';
 
        if ($numbers_only) {
            $consonants = '0123456789';
            $vowels = '9876543210';
        }
        
	$password = '';
	$alt = time() % 2;
	for ($i = 0; $i < $length; $i++) {
		if ($alt == 1) {
			$password .= $consonants[(rand() % strlen($consonants))];
			$alt = 0;
		} else {
			$password .= $vowels[(rand() % strlen($vowels))];
			$alt = 1;
		}
	}
	return $password;
}