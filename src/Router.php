<?php
/**
 * Created by PhpStorm.
 * User: Zuev Yuri
 * Date: 20.07.2021
 * Time: 0:51
 */

namespace Rmphp\Router;

use Psr\Http\Message\RequestInterface;
use Rmphp\Foundation\MatchObject;
use Rmphp\Foundation\RouterInterface;

class Router implements RouterInterface {

	private array $routes = [];
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
	public function withRules(array $rules): void {

		// сортируем ключи в правилах
		uasort($rules, function ($element1, $element2) {
			// если в правилах указана позиция в ключе pos
			$posElement1 = (!empty($element1['pos'])) ? $element1['pos'] : 0;
			$posElement2 = (!empty($element2['pos'])) ? $element2['pos'] : 0;
			if($posElement1 == $posElement2) {
				// количество частей url
				$lengthElement1 = count(explode("/", rtrim($element1['key'],"/")));
				$lengthElement2 = count(explode("/", rtrim($element2['key'],"/")));
				return ($lengthElement1 == $lengthElement2) ? 0 : (($lengthElement1 < $lengthElement2) ? 1 : -1);
			}
			return ($posElement1 > $posElement2) ? 1 : -1;
		});

		foreach ($rules as $rulesKey => $rulesNode) {
			// проверка формата
			if (!isset($rulesNode['key'], $rulesNode['route'])) continue;
			// удаляем спецсимволы
			$realPattern = preg_replace("'[()\'\"]'", "", $rulesNode['key']);
			// преобразуем псевдомаску в реальную маску
			$realPattern = preg_replace("'<([A-z0-9]+?):(.+?)>'", "(?P<$1>$2)", $realPattern);
			// заменяем алиасы на регвыражения
			$realPattern = str_replace(array("[num]", "[pth]", "[any]"), array("[0-9]+", "[^/]+", ".*"), $realPattern);
			// при наличии слеша в конце правила url должно строго ему соответствовать
			$end = (preg_match("'/$'", $realPattern)) ? "$" : "";
			// меняем запись на паттерн
			$rules[$rulesKey]['key'] = $realPattern.$end;
		}
		$this->routes = $rules;
	}

	public function match(RequestInterface $request): ?MatchObject {

		foreach ($this->routes as $routeKey => $routeNode) {
			// если для правила определен метод и он не совпал смотри далее
			if(!empty($routeNode['withMethod']) && is_array($routeNode['withMethod']) && false === (array_search($request->getMethod(), $routeNode['withMethod']))) continue;
			if(!empty($routeNode['withoutMethod']) && is_array($routeNode['withoutMethod']) && false !== (array_search($request->getMethod(), $routeNode['withoutMethod']))) continue;

			// вычисляем рабочий url
			$currentUrlString = preg_replace("'^".preg_quote($this->startPoint)."'", "/", $request->getUri()->getPath());

			// в цикле проверяем совпадения текущей строки с правилами
			if (preg_match("'^".$routeNode['key']."'", $currentUrlString, $matches)) {
				// если в результате есть именные ключи от ?P<name>, пытаемся произвести замену <name> в части inc
				foreach ($matches as $matchesKey => $matchesVal) {
					if (!is_numeric($matchesKey)) {
						$routeNode['route']['action'] = str_replace("<".ucfirst($matchesKey).">", ucfirst($matchesVal), $routeNode['route']['action']);
						$routeNode['route']['action'] = str_replace("<".$matchesKey.">", $matchesVal, $routeNode['route']['action']);

						$routeNode['route']['method'] = str_replace("<".ucfirst($matchesKey).">", ucfirst($matchesVal), $routeNode['route']['method']);
						$routeNode['route']['method'] = str_replace("<".$matchesKey.">", $matchesVal, $routeNode['route']['method']);
						if (!empty($routeNode['route']['params'])) {
							$routeNode['route']['params'] = str_replace("<".$matchesKey.">", $matchesVal, $routeNode['route']['params']);
						}
					}
				}
				// чистка маркеров
				$routeNode['route']['action'] = preg_replace("'<.+>'", "", $routeNode['route']['action']);
				$routeNode['route']['method'] = preg_replace("'<.+>'", "", $routeNode['route']['method']);
				if (!empty($routeNode['route']['params'])) {
					$routeNode['route']['params'] = preg_replace("'<.+>'", "", $routeNode['route']['params']);
				}

				$className  = (!empty($routeNode['route']['action'])) ? $routeNode['route']['action'] : "";
				$methodName = (!empty($routeNode['route']['method'])) ? $routeNode['route']['method'] : "";
				$paramSet   = (!empty($routeNode['route']['params'])) ? explode(",",str_replace(" ", "", $routeNode['route']['params'])) : [];

				$params = [];
				foreach ($paramSet as $key => $param){
					if(empty($param)) continue;
					$params[$key] = (preg_match("'^[0-9]+$'", $param)) ? $params[$key] = (int) $param : $param;
				}
				return new MatchObject($className, $methodName, $params);
			}
		}
		return null;

	}
}