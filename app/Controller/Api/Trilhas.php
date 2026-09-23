<?php 

namespace App\Controller\Api;
use \App\Model\Entity\Trilhas as EntityTrilhas;
use \App\Model\Db\Pagination;

class Trilhas extends Api{

	private static function getTrilhaItems($request,&$obPagination){
		$itens = [];

		//QUANTIDADE TOTAL DE REGISTROS
		$quantidadeTotal = EntityTrilhas::getTrilha(null,null,null,'COUNT(*) as qtd')->fetchObject()->qtd;

		//PAGINA ATUAL
		$queryParams = $request->getQueryParams();
		$paginaAtual = $queryParams['page'] ?? 1;

		//INSTANCIA DE PAGINAÇÃO
		$obPagination = new Pagination($quantidadeTotal,$paginaAtual,5);

		//RESULTADOS DA PAGINA
		$results = EntityTrilhas::getTrilha(null,'id DESC', $obPagination->getLimit());

		//REDERIZA O ITEM
		while ($obTrilhas = $results->fetchObject(EntityTrilhas::class)) {
			$itens[] = [

				'id' => (int)$obTrilhas->id,
				'nome' => $obTrilhas->nome,
				'carga_h' => $obTrilhas->carga_h,
				'valor_mensal' => $obTrilhas->valor_mensal,
				'id_categoria' => $obTrilhas->id_categoria

			];
		}

		//RETORNA OS DEPOIMENTOS
		return $itens;
	}

	public static function getTrilha($request){

		return [
			'trilhas' => self::getTrilhaItems($request,$obPagination),
			'paginacao' => parent::getPagination($request,$obPagination)
		];
	}

	public static function getTrilhaById1($request,$id){
		
		if(!is_numeric($id)){
			throw new \Exception("O id '".$id."' não é válido", 400);
		}
		$obTrilhas = EntityTrilhas::getTrilhaById($id);
		
		if(!$obTrilhas instanceof EntityTrilhas){
			throw new \Exception("A trilha ".$id." não foi encontrada", 404);
		}

		return [
			'id' => (int)$obTrilhas->id,
			'nome' => $obTrilhas->nome,
			'carga_h' => $obTrilhas->carga_h,
			'id_categoria' => $obTrilhas->id_categoria
		];

	}

	public static function setNewTrilha($request){
		//POST VARS
		$postVars = $request->getPostVars();
		
		//VALIDA OS CAMPOSS OBRIGATORIOS
		if(!isset($postVars['nome']) or !isset($postVars['carga_h'])){
			throw new \Exception("Os campos 'nome' e 'carga horaria' são obrigatórios",400);
		}

		//NOVO DEPOIMENTO
		$obTrilhas = new EntityTrilhas;
		$obTrilhas->nome = $postVars['nome'];
		$obTrilhas->carga_h = $postVars['carga_h'];
		$obTrilhas->id_categoria = 2;
		$obTrilhas->id_admin = 1;
		$obTrilhas->cadastrar();

		//RETORNA OS DETALHES DO DEPOIMENTO CADASTRADO

		return [
			'id' => (int)$obTrilhas->id,
			'nome' => $obTrilhas->nome,
			'carga_h' => $obTrilhas->carga_h,
			'id_categoria' => $obTrilhas->id_categoria
		];
	}

	public static function setEditTrilha($request,$id){
		//POST VARS
		$postVars = $request->getPostVars();
		
		//VALIDA OS CAMPOSS OBRIGATORIOS
		if(!isset($postVars['nome']) or !isset($postVars['carga_h'])){
			throw new \Exception("Os campos 'nome' e 'carga horaria' são obrigatórios",400);
		}

		//BUSCA O REGISTRO
		$obTrilhas = EntityTrilhas::getTrilhaById($id);

		//VALIDA A INSTANCIA
		if(!$obTrilhas instanceof EntityTrilhas){
			throw new \Exception("a Trilha ".$id." não foi encontrada", 404);
		}

		//ATUALIZA O REGISTRO
		$obTrilhas->nome = $postVars['nome'];
		$obTrilhas->carga_h = $postVars['carga_h'];
		$obTrilhas->id_categoria = $postVars['id_categoria'];
		$obTrilhas->atualizar();

		//RETORNA OS DETALHES DO DEPOIMENTO ATUALIZADO

		return [
			'id' => (int)$obTrilhas->id,
			'nome' => $obTrilhas->nome,
			'carga_h' => $obTrilhas->carga_h,
			'id_categoria' => $obTrilhas->id_categoria
		];
	}

	public static function setDeleteTrilha($request,$id){

		//BUSCA O REGISTRO
		$obTrilhas = EntityTrilhas::getTrilhaById($id);

		//VALIDA A INSTANCIA
		if(!$obTrilhas instanceof EntityTrilhas){
			throw new \Exception("A trilha ".$id." não foi encontrado", 404);
		}

		//EXCLUI O REGISTRO
		$obTrilhas->excluir();

		//RETORNA OS DETALHES DA TRILHA ATUALIZADA

		return [
			'sucesso' => true
		];
	}


}