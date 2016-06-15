<?php
class SchemalessBehavior extends ModelBehavior {
	public $name = 'Schemaless';
	public $settings = [];
	protected $_defaultSettings = [];
	
	public function setup(&$Model, $config = []) {}

	public function beforeSave(&$Model) {
		$Model->cacheSources = false;
		$Model->schema(true);
		return true;
	}
}
