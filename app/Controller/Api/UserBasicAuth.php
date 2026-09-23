<?php 

namespace App\Controller\Api;

class UserBasicAuth{

	public static function getDetails($request){

		return [
			'nome' => 'API - CTI',
			'versao' =>  'v1.0.0',
			'autor' => 'Danilo Rodrigues'
		];
	}

	//RETORNA DETALHES PAGINAÇÃO
	protected static function getPagination($request,$obPagination){
		//QUERY PARAMS
		$queryParams = $request->getQueryParams();

		//PÁGINAS
		$pages = $obPagination->getPages();

		return [
			'paginaAtual' => isset($queryParams['page']) ? (int)$queryParams['page'] : 1,
			'quantidadePaginas' => !empty($pages) ? count($pages) : 1
		];
	}


}