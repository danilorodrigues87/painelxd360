<?php

namespace App\Http\Middleware;

class Queue{

	//MAPEAMENTO DE MIDDLEWARE
	private static $map = [];

	//MAPEAMENTO DE MIDDLEWARES QUE SERÃO CARREGADOS EM TODAS AS ROTAS
	private static $default = [];

	//FILA DE MIDDLEWARES A SEREM EXECUTADOS
	private $middlewares = [];

	//EXECUTA O CONTROLADOR (CLOSURE)
	private $controller;

	//ARGUMENTOS DA FUNÇÃO CONTROLLER
	private $controllerArgs = [];



	//CONSTROI A CLASSE DE FILA DE MIDDLEWARE
	public function __construct($middlewares,$controller,$controllerArgs){
		$this->middlewares = array_merge(self::$default,$middlewares);
		$this->controller = $controller;
		$this->controllerArgs = $controllerArgs;

	}

	//DEFINE O MAPEAMENTO DE MIDDLEWARES
	public static function setMap($map){
		self::$map = $map;
	}

	//DEFINE O MAPEAMENTO DE MIDDLEWARES PADRÕES
	public static function setDefault($default){
		self::$default = $default;
	}

	//EXECUTA O PROXIMO NIVEL DA FILA DE MIDDLEWARES
	public function next($request){

		//VERIFICA SE A FILA ESTÁ VAZIA
		if(empty($this->middlewares)) return call_user_func_array($this->controller,$this->controllerArgs);

		//MIDDLEWARE
		$middleware = array_shift($this->middlewares);

		//VERIFICA O MAPEAMENTO
		if(!isset(self::$map[$middleware])){
			throw new \Exception('Problemas ao carregar o middleware da requisição', 500);
		}
		
		//NEXT
		$queue = $this;
		$next = function($request) use($queue){
			return $queue->next($request);
		};

		//EXECUTA O MIDDLEWARE
		return (new self::$map[$middleware])->handle($request,$next);
	}

	

}