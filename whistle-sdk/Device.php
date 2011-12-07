<?php

/**
 * Device Uri and Sample definition
 *
 * @author Francis Genet & Peter Defebvre
 * @version 1.3
 * @since July 14, 2011 - 1.0
 * 
 */

require_once 'CrossbarSession.php';

class Devices extends CrossbarSession {

	protected $URI = 'devices';
	protected $SAMPLE = array(
		'name' => null,
                'mac_address' => null,
                'device_type' => null,
		'sip' => array(
			'realm' => null,
			'method' => null,
			'username' => null,
			'password' => null,
			'invite_format' => null,
			'custom_sip_headers' => null
		),
		'caller_id' => array(
                        'external' => array(
                            'name' => NULL,
                            'number' => NULL
                        ),
                        'internal' => array(
                            'name' => NULL,
                            'number' => NULL
                        ),
                        'emergency' => array(
                            'name' => NULL,
                            'number' => NULL
                        )
		),
		'caller_id_options' => array(
			'reformat' => null
		),
		'media' => array(
			'progress_timeout' => null,
			'ignore_early_media' => null,
			'bypass_media' => null,
			'audio' => array(
                            'codecs' => null
                        ),
                        'video' => array(
                            'codecs' => null
                        ),
                        'fax' => array(
                            'option' => null
                        )
		),
		'ringtones' => array(
			'internal' => null,
			'external' => null
		),
		'owner_id' => null
	);

}
?>
