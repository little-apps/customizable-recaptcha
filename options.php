<?php

(defined('WP_GRECAPTCHA_FILE')) OR die('This file cannot be accessed directly.');

class WPGRecaptchaOptions {
	protected $option_name;
	
	protected $data = array();
	protected $data_defaults = false;
	
	function __construct($option_name, $defaults = array()) {
		if (!is_string($option_name) || empty($option_name))
			throw new Exception('Option name must be a string and cannot be empty.');
		
		$this->option_name = $option_name;
		
		if (!empty($defaults) && is_array($defaults))
			$this->data_defaults = $defaults;
		
		$this->get_options();
	}
	
	// It is recommended to use register_setting() to validate and set options instead of this function
	public function __set($name, $value) {
		$this->data[$name] = $value;
		
		$this->update_options();
	}
	
	public function __get($name) {
		if (isset($this->data[$name]))
			return $this->data[$name];
		else if (isset($this->data_defaults[$name]))
			return $this->data_defaults[$name];
		else
			return null;
	}
	
	public function __isset($name) {
		return (isset($this->data[$name]) || isset($this->data_defaults[$name]) ? true : false);
	}
	
	public function get_option_default($name) {
		if (isset($this->data_defaults[$name]))
			return $this->data_defaults[$name];
		else
			return null;
	}
	
	public function get_option_name($append = '') {
		$option_name = $this->option_name;
		
		if (!empty($append))
			$option_name .= $append;
		
		return $option_name;
	}
	
	public function get_options() {
		$defaults = $this->data_defaults;
		
		$this->data = get_option($this->option_name, $defaults);
	}
	
	public function update_options() {
		if (!empty($this->data_defaults))
			$data = array_merge($this->data_defaults, $this->data);
		else
			$data = $this->data;
		
		update_option($this->option_name, $data);
	}
	
	public function delete_options() {
		delete_option($this->option_name);
	}
}