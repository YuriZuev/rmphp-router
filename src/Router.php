<?php

namespace Rmphp\Router;

use Psr\Http\Message\RequestInterface;
use Rmphp\Foundation\MatchObject;
use Rmphp\Foundation\RouterInterface;

class Router implements RouterInterface {

	private array $rules = [];
	private string $startPoint = "/";

	/**
	 * @param string $startPoint
	 */
	public function setStartPoint(string $startPoint): void {
		$this->startPoint = $startPoint;
	}

	/**
	 * @param array $rules
	 */
	public function withRules(array $rules): void
	{
		$this->rules = [];
		foreach ($rules as $rulesKey => $rulesNode) {
			// проверка формата
			if (!isset($rulesNode['key'], $rulesNode['routes'])) continue;
			// преобразуем псевдомаску в реальную маску
			// заменяем алиасы на регвыражения
			$realPattern = preg_replace("'<([A-z0-9]+?):@any>'", "(?P<$1>.*)", $rulesNode['key']);
			$realPattern = preg_replace("'<([A-z0-9]+?):@num>'", "(?P<$1>[0-9]+)", $realPattern);
			$realPattern = preg_replace("'<([A-z0-9]+?):@path>'", "(?P<$1>[^/]+)", $realPattern);
			// поддерживаем свободное регулярное выражение в псевдомаске
			$realPattern = preg_replace("'<([A-z0-9]+?):(.+?)>'", "(?P<$1>$2)", $realPattern);
			// заменяем алиасы на регвыражения
			$realPattern = str_replace(["<@any>", "<@num>", "<@path>"], [".*", "[0-9]+", "[^/]+"], $realPattern);
			// при наличии слеша в конце правила url должно строго ему соответствовать
			$end = (preg_match("'/$'", $realPattern)) ? "$" : "";
			// меняем запись на паттерн
			$this->rules[$rulesKey] = $rulesNode;
			$this->rules[$rulesKey]['key'] = "'^".$realPattern.$end."'";
		}
	}

	public function match(RequestInterface $request): ?array {

		foreach ($this->rules as $rule) {
			// если для правила определен метод и он не совпал смотри далее
			if(!empty($rule['withMethod']) && is_array($rule['withMethod']) && false === (array_search($request->getMethod(), $rule['withMethod']))) continue;
			if(!empty($rule['withoutMethod']) && is_array($rule['withoutMethod']) && false !== (array_search($request->getMethod(), $rule['withoutMethod']))) continue;

			// вычисляем рабочий url
			$currentUrlString = preg_replace("'^".preg_quote($this->startPoint)."'", "/", $request->getUri()->getPath());

			// в цикле проверяем совпадения текущей строки с правилами
			if (preg_match($rule['key'], $currentUrlString, $matches)) {
				$routes = [];
				foreach ($rule['routes'] as $route) {

					// если в результате есть именные ключи от ?P<name>, пытаемся произвести замену <name> в части inc
					foreach ($matches as $matchesKey => $matchesVal) {
						if (!is_numeric($matchesKey)) {

							$route['action'] = str_replace("<".$matchesKey.">", ucfirst($matchesVal), $route['action']);
							$route['method'] = str_replace("<".$matchesKey.">", ucfirst($matchesVal), $route['method']);

							if (!empty($route['params'])) {
								$route['params'] = str_replace("<".$matchesKey.">", $matchesVal, $route['params']);
							}
						}
					}

					// чистка маркеров
					$route['action'] = preg_replace("'<.+>'", "", $route['action']);
					$route['method'] = preg_replace("'<.+>'", "", $route['method']);
					if (!empty($route['params'])) {
						$route['params'] = preg_replace("'<.+>'", "", $route['params']);
					}

					$className  = (!empty($route['action'])) ? $route['action'] : "";
					$methodName = (!empty($route['method'])) ? $route['method'] : "";
					$paramSet   = (!empty($route['params'])) ? explode(",",str_replace(" ", "", $route['params'])) : [];

					$params = [];
					foreach ($paramSet as $key => $param){
						if(empty($param)) continue;
						$params[$key] = (preg_match("'^[0-9]+$'", $param)) ? (int) $param : $param;
					}

					$routes[] = new MatchObject($className, $methodName, $params);
				}
				return $routes;
			}
		}
		return null;
	}
}