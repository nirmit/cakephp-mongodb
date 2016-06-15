<?php
class SqlCompatibleBehavior extends ModelBehavior {
	public $name = 'SqlCompatible';
	public $settings = [];
	protected $_defaultSettings = [
		'convertDates' => true,
		'operators' => [ '!=' => '$ne', '>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', 'IN' => '$in', 'NOT' => '$not', 'NOT IN' => '$nin' ]
	];

	public function setup(&$Model, $config = []) {
		$this->settings[$Model->alias] = array_merge($this->_defaultSettings, $config);
	}

	public function afterFind(&$Model, $results, $primary) {
		if ($this->settings[$Model->alias]['convertDates']) {
			$this->convertDates($results);
		}
		return $results;
	}

	public function beforeFind(&$Model, $query) {
		if (is_array($query['order'])) {
			$this->_translateOrders($Model, $query['order']);
		}
		if (is_array($query['conditions']) && $this->_translateConditions($Model, $query['conditions'])) {
			return $query;
		}
		return $query;
	}

	public function convertDates(&$results) {
		if (is_array($results)) {
			foreach($results as &$row) {
				$this->convertDates($row);
			}
		} elseif (is_a($results, 'MongoDate')) {
			$results = date('Y-M-d h:i:s', $results->sec);
		}
	}

	protected function _translateOrders(&$Model, &$orders) {
		if(!empty($orders[0])) {
			foreach($orders[0] as $key => $val) {
				if(preg_match('/^(.+) (ASC|DESC)$/i', $val, $match)) {
					$orders[0][$match[1]] = $match[2];
					unset($orders[0][$key]);
				}
			}
		}
	}

	protected function _translateConditions(&$Model, &$conditions) {
		$return = false;
		foreach($conditions as $key => &$value) {
			$uKey = strtoupper($key);
			if (substr($uKey, -5) === 'NOT IN') {
				$conditions[substr($key, 0, -5)]['$nin'] = $value;
				unset($conditions[$key]);
				$return = true;
				continue;
			}
			if ($uKey === 'OR') {
				unset($conditions[$key]);
				foreach($value as $key => $part) {
					$part = [ $key => $part ];
					$this->_translateConditions($Model, $part);
					$conditions['$or'][] = $part;
				}
				$return = true;
				continue;
			}
			if ($key === $Model->primaryKey && is_array($value)) {
				$isMongoOperator = false;
				foreach($value as $idKey => $idValue) {
					if(substr($idKey,0,1) === '$') {
						$isMongoOperator = true;
						continue;
					}
				}
				unset($idKey, $idValue);
				if($isMongoOperator === false) {
					$conditions[$key] = [ '$in' => $value ];
				}
				$return = true;
				continue;
			}
			if (substr($uKey, -3) === 'NOT') {
				$childKey = key($value);
				$childValue = current($value);

				if (in_array(substr($childKey, -1), [ '>', '<', '=' ] ) ) {
					$parts = explode(' ', $childKey);
					$operator = array_pop($parts);
					if ($operator = $this->_translateOperator($Model, $operator)) {
						$childKey = implode(' ', $parts);
					}
				} else {
					$conditions[$childKey]['$nin'] = (array)$childValue;
					unset($conditions['NOT']);
					$return = true;
					continue;
				}

				$conditions[$childKey]['$not'][$operator] = $childValue;
				unset($conditions['NOT']);
				$return = true;
				continue;
			}
			if (substr($uKey, -5) === ' LIKE') {
				if ($value[0] === '%') {
					$value = substr($value, 1);
				} else {
					$value = '^' . $value;
				}
				
				if (substr($value, -1) === '%') {
					$value = substr($value, 0, -1);
				} else {
					$value .= '$';
				}
				$value = str_replace('%', '.*', $value);

				$conditions[substr($key, 0, -5)] = new MongoRegex("/$value/i");
				unset($conditions[$key]);
				$return = true;
				continue;
			}

			if (!in_array(substr($key, -1), [ '>', '<', '=' ] ) ) {
				$return = true;
				continue;
			}
			if (is_numeric($key && is_array($value))) {
				if ($this->_translateConditions($Model, $value)) {
					$return = true;
					continue;
				}
			}
			$parts = explode(' ', $key);
			$operator = array_pop($parts);
			if ($operator = $this->_translateOperator($Model, $operator)) {
				$newKey = implode(' ', $parts);
				$conditions[$newKey][$operator] = $value;
				unset($conditions[$key]);
				$return = true;
			}
			if (is_array($value)) {
				if ($this->_translateConditions($Model, $value)) {
					$return = true;
					continue;
				}
			}
		}
		return $return;
	}

	protected function _translateOperator($Model, $operator) {
		if (!empty($this->settings[$Model->alias]['operators'][$operator])) {
			return $this->settings[$Model->alias]['operators'][$operator];
		}
		return '';
	}
}
