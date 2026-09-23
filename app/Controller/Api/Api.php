<?php 

namespace App\Controller\Api;

class Api{

	public static function getDetails($request){

		return [
			'nome' => 'API - CTI',
			'versao' =>  'v1.0.0',
			'autor' => 'Danilo Rodrigues',
			'Descrição' => 'API do Sistema CTI - desenvolvida para futuras integrações como aplicativos mobile, sistemas externos em futuras parcerias entre outros.'
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