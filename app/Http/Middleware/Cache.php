<?php 

namespace App\Http\Middleware;
use \App\Utils\Cache\File as CacheFile;

class Cache{

	//VERIFICA SE A REQUEST ATUAL É CHACHEAVEL
	private function isCacheable($request){
	//VALIDA O TEMPO DE CACHE

		if(getenv('CACHE_TIME') <= 0){
			return false;
		}

		//VALIDA O METODO DA REQUISIÇÃO
		if($request->getHttpMethod() != 'GET'){
			return false;
		}

	//CACHEABLE
		return true;
	}

	private function getHash($request){
		//URI DA ROTA
		$uri = $request->getRouter()->getUri();

		//QUERY PARAMS
		$queryParams = $request->getQueryParams();
		$uri .= !empty($queryParams) ? '?'.http_build_query($queryParams) : '';

		//REMOVE AS BARRAS E RETORNA AS HASH
		return rtrim('route-'.preg_replace('/[^0-9a-zA-Z]/','-',ltrim($uri,'/')),'-');	
		
	}

	//EXECUTA O MIDDLEWARE
	public function handle($request,$next){

		//VERIFICA SE A REQUEST ATUAL É CACHEÁVEL
		if(!$this->isCacheable($request)) return $next($request);
		
		//HASH DO CACHE
		$hash = $this->getHash($request);


		//RETORNA OS DADOS DO CACHE
		return CacheFile::getCache($hash,getenv('CACHE_TIME'),function() use($request,$next){
			return $next($request);
		});
	}

}