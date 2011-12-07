<?php

namespace csv;

function register_autoload() {
	spl_autoload_register(function($class) {            
		$base = __DIR__ . DIRECTORY_SEPARATOR;
		$path = $base . str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php';
		if (is_file($path)) {
			require_once $path;
		}
        });
}