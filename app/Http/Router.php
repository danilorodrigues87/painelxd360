<?php

namespace App\Http;

use \Closure;
use \Exception;
use \Throwable;
use \ReflectionFunction;
use \App\Http\Middleware\Queue as MiddlewareQueue;
use \App\Http\Middleware\CorsStudent;
use \App\Http\Middleware\CorsConect;
use \App\Utils\View;


class Router{

	private $url = ''; // URL pública da raiz do projeto (links / redirects)
	private $prefix = ''; // Prefixo de montagem (pasta onde o index.php vive)
	private $routes = []; // Index de todas rotas
	private $request; // Instancia de Request
	private $contentType = 'text/html'; // ContentType padrão

	// Metodo inicia as classes
	public function __construct($url){
		$this->request = new Request($this);
		$this->url = rtrim((string)$url, '/');
		$this->setPrefix();
	}

	//ALTERA O VALOR DO CONTENTTYPE
	public function setContentType($contentType){
		$this->contentType = $contentType;
	}

	/**
	 * Prefixo = pasta real do deploy (SCRIPT_NAME), não o path do .env.
	 * Local: /pjt/painel-cti | Produção na raiz: (vazio) | Subpasta no servidor: /app
	 */
	private function setPrefix(){
		$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
		if ($scriptDir === '/' || $scriptDir === '.') {
			$scriptDir = '';
		}
		$this->prefix = rtrim($scriptDir, '/');
	}

	//Adiciona uma rota na classe
	private function addRoute($method,$route,$params = []){
		//Validação dos parametros
		foreach ($params as $key=>$value) {
			if($value instanceof Closure){
				$params['controller'] = $value;
				unset($params[$key]);
				continue;
			}
		}

		//MIDDLEWARES DA ROTA
		$params['middlewares'] = $params['middlewares'] ?? [];

		//VARIAVEIS DA ROTA
		$params['variables'] = [];

		// {nome+} captura um ou mais segmentos (ex.: auth/login)
		$patternPlus = '/\{([a-zA-Z_][a-zA-Z0-9_]*)\+\}/';
		if (preg_match_all($patternPlus, $route, $matchesPlus)) {
			$route = preg_replace($patternPlus, '(.+)', $route);
			$params['variables'] = array_merge($params['variables'], $matchesPlus[1]);
		}

		//PADRÃO DE VALIDAÇÃO DAS VARIAVEIS DAS ROTAS
		// ([^/]+) = um segmento; evita /courses/{id} capturar /courses/2/lessons/2
		$patternVariable = '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/';
		if (preg_match_all($patternVariable, $route, $matches)) {
			$route = preg_replace($patternVariable, '([^/]+)', $route);
			$params['variables'] = array_merge($params['variables'], $matches[1]);
		}

		//REMOVE BARRA NO FINAL DA ROTA (mantém "/" para a home/login)
		$route = rtrim($route,'/');
		if ($route === '') {
			$route = '/';
		}

		//PADRÃO DE VALIDAÇÃO DA URL
		$patternRoute = '/^'.str_replace('/', '\/', $route).'$/';
		
		//ADICIONA A ROTA DENTRO DA CLASSE
		$this->routes[$patternRoute][$method] = $params;
	}

	//definir um rota de GET
	public function get($route,$params = []){
		return $this->addRoute('GET',$route,$params);
	}

	//definir um rota de post
	public function post($route,$params = []){
		return $this->addRoute('POST',$route,$params);
	}

	//definir um rota de PUT
	public function put($route,$params = []){
		return $this->addRoute('PUT',$route,$params);
	}

	//definir um rota de DELETE
	public function delete($route,$params = []){
		return $this->addRoute('DELETE',$route,$params);
	}

	// CORS preflight (portal aluno / APIs)
	public function options($route,$params = []){
		return $this->addRoute('OPTIONS',$route,$params);
	}

	public function getUri(){
		// URI da request (ex.: /pjt/painel-cti/privacidade)
		$uri = $this->request->getUri();
		$prefix = $this->prefix;
		if ($prefix !== '' && strpos($uri, $prefix) === 0) {
			$uri = substr($uri, strlen($prefix)) ?: '/';
		}
		// Sempre com barra inicial (rotas cadastradas como /privacidade, /painel, …)
		$uri = '/'.ltrim($uri, '/');
		$uri = rtrim($uri, '/');
		return $uri === '' ? '/' : $uri;
	}

	//retorna os dados da rota atual
	private function getRoute(){
		//URI
		$uri = $this->getUri();
		
		//METHOD
		$httpMethod = $this->request->getHttpMethod();

		// URI bateu em algum padrão (ex.: OPTIONS catch-all), mas sem o método atual
		$uriMatched = false;
		
		//VALIDA AS ROTAS 
		foreach($this->routes as $patternRoute=>$methods){

			//VERIFICA SE A URI BATE O PADRÃO
			if(preg_match($patternRoute,$uri,$matches)){
				$uriMatched = true;

				//VERIFICA O METHOD — se não tiver, tenta o próximo padrão
				// (evita 405 quando OPTIONS /{path} casa antes de GET /courses)
				if(!isset($methods[$httpMethod])){
					continue;
				}

				//REMOVE A PRIMEIRA POSIÇÃO
				unset($matches[0]);

				//VARIAVEIS PROCESSADAS 
				$keys = $methods[$httpMethod]['variables'];
				$methods[$httpMethod]['variables'] = array_combine($keys, $matches);
				$methods[$httpMethod]['variables']['request'] = $this->request; 


				//RETORNA OS PARAMETROS DA ROTA
				return $methods[$httpMethod];
			}
		}

		if($uriMatched){
			throw new Exception(View::render('erros/405',[]), 405);
		}

		//URL NÃO ENCONTRADA
		throw new Exception(View::render('erros/404',[]), 404);

	}

	

	//EXECUTA A ROTA ATUAL
	public function run(){
		try {
			$uri = $this->getUri();

			// Preflight: CORS sem depender de rota cadastrada (OPTIONS não existe nas rotas GET/POST)
			if (strtoupper($this->request->getHttpMethod()) === 'OPTIONS') {
				if (CorsStudent::isStudentApiUri($uri)) {
					CorsStudent::applyHeaders();
					return new Response(204, '', 'application/json');
				}
				if (CorsConect::isConectApiUri($uri)) {
					CorsConect::applyHeaders();
					return new Response(204, '', 'application/json');
				}
			}

			//OBTEM A ROTA ATUAL
			$route = $this->getRoute();
		
		//VERIFICA O CONTROLADOR - A URL não pôde ser processada
			if(!isset($route['controller'])){
				throw new Exception(View::render('erros/405',[]), 500);

			}

			//ARGUMENTOS DA FUNÇÃO
			$args = [];
 
			//REFLECTION
			$reflection = new ReflectionFunction($route['controller']);
			foreach($reflection->getParameters() as $parameter){
				$name = $parameter->getName();
				$args[$name] = $route['variables'][$name] ?? '';
			}

			//RETORNA A EXECUÇÃO DA FILA DE MIDDLEWARES
			return (new MiddlewareQueue($route['middlewares'],$route['controller'],$args))->next($this->request);

		} catch (\Throwable $e) {
			// 404/405 da API externa ainda precisam de CORS (middleware não chega a rodar)
			$errUri = $this->getUri();
			if (CorsStudent::isStudentApiUri($errUri)) {
				CorsStudent::applyHeaders();
				$this->contentType = 'application/json';
			} elseif (CorsConect::isConectApiUri($errUri)) {
				CorsConect::applyHeaders();
				$this->contentType = 'application/json';
			}
			$code = (int)$e->getCode();
			if ($code < 400 || $code > 599) {
				$code = 500;
			}
			return new Response($code,$this->getErrorMessage($e->getMessage()),$this->contentType);
		}
	}

	private function getErrorMessage($message){

		switch ($this->contentType) {
			case 'application/json':
				if (is_string($message) && (str_contains($message, '<!doctype') || str_contains($message, '<html'))) {
					$message = 'Recurso não encontrado.';
				}
				return [
					'message' => $message,
					'erro' => $message,
				];
			
			default:
				return $message;
		}
	}

	//RETORNA URL ATUAL
	public function getCurrentUrl(){
		return $this->url.$this->getUri();
	}

	//REDIRECIONA A URL
	public function redirect($route){
		$route = (string)$route;
		if (preg_match('#^https?://#i', $route)) {
			header('Location: '.$route);
			exit;
		}
		$url = $this->url.'/'.ltrim($route, '/');
		header('Location: '.$url);
		exit;
	}

}
