<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\Contratos as EntityContratos;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\TenantHelper;

class Contratos extends Page{

	//RETORNA O FORMULARIO
	public static function index($request){
		//CONTEÚDO DE FORMULÁRIO
		$content = View::render('admin/modules/contratos/index',[]);

		//RETORNA A PÁGINA COMPLETA
		/**
         * TITULO DA PAGINA
         * CONTEUDO
         * CURRENTSESSION SESSÃO ATUAL
         * REQUEST SE NESCESSÁRIO
         */
		return parent::getPanel('Contratos',$content,'Empresa');
	}

	private static function getContratosItens($request,&$obPagination){

		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

		$itens = '';

		//QUANTIDADE TOTAL DE REGISTROS
		$quantidadeTotal = EntityContratos::getContratos('id_admin = ' . (int)$id_admin,null,null,'COUNT(*) as qtd')->fetchObject()->qtd;

		//PAGINA ATUAL
		$queryParams = $request->getPostVars();
		$paginaAtual = $queryParams['page'] ?? 1;

		//INSTANCIA DE PAGINAÇÃO
		$obPagination = new Pagination($quantidadeTotal,$paginaAtual,5);

		//RESULTADOS DA PAGINA
		$results = EntityContratos::getContratos('id_admin = ' . (int)$id_admin, 'id ASC', $obPagination->getLimit());

		//REDERIZA O ITEM
		while ($obData = $results->fetchObject(EntityContratos::class)) {

		$itens .= '<tr>
	<td>'.$obData->nome.'</td>
	<td>
		<div class="dropdown">
			<button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
				<i class="far fa-edit fa-lg"></i>
			</button>
			<ul class="dropdown-menu">
				<li>
					<a class="dropdown-item" href="#" onclick="list_itens('.$obData->id.', \'editar\')"><i class="far fa-edit fa-lg"></i> Editar</a>
				</li>
				<li>
					<a class="dropdown-item" href="#" onclick="excluir('.$obData->id.')" ><i class="far fa-trash-alt fa-lg"></i> Excluir</a>
				</li>
			</ul>
		</div>
	</td>
</tr>';
		    
		}


		$table = '<div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
	<thead>
		<tr>
			<th>Nome</th>
			<th>Ações</th>
		</tr>
	</thead>
	<tbody>'.$itens.'</tbody>
</table>
</div>
</div>';

		//RETORNA
		return $table;
	}

	public static function getInfo($request){


	//CONTEÚDO 
		$conteudo = [
			'itens' => self::getContratoItens($request,$obPagination),
			'pagination' => parent::getPagination($request,$obPagination)
		];

		return parent::jsonLista($conteudo);

	}

	private static function getForm($request) {
    $postVars = $request->getPostVars();

    if ($postVars['funcao'] == 'editar') {
        $id = (int)($postVars['id'] ?? 0);
        $id_admin = parent::getIdAdminInt();
        if (!TenantHelper::pertence('contratos', $id, $id_admin)) {
            return json_encode(['erro' => 'Registro não encontrado.']);
        }
        $dados = (array) EntityContratos::getContratoById($id);
    }

     $form = '<form id="form" method="post">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="exampleModalLabel">Contrato</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
            <div class="row">
                <div id="response"></div>
                <div class="form-group col-lg-6 col-md-9 col-sm-12">
                <label>Nome do contrato</label>
		<input type="text" name="nome" value="' . htmlspecialchars(@$dados['nome'], ENT_QUOTES, 'UTF-8') . '" class="form-control" required>
		</div>

		<div class="form-group col-12 my-3">
		<label>Conteúdo</label>
                <textarea class="editor" name="conteudo" id="editor"></textarea>
		</div>

            </div>
            </div>
            <div class="modal-footer">
                <input value="' . @$dados['id'] . '" type="hidden" name="id">
                <button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>';

    return $form;
}


	public static function getNewContrato($request){

		$form = self::getForm($request);
		return json_encode($form);
	}

	public static function setNewContrato($request){

		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

		$postVars = $request->getPostVars();

		if($postVars['id'] != ''){

			$id = (int)$postVars['id'];
			if (!TenantHelper::pertence('contratos', $id, (int)$id_admin)) {
				return 'Registro não encontrado.';
			}

			//NOVA INSTANCIA
		$obData = new EntityContratos;
		$obData->id = $id;
		$obData->nome = $postVars['nome'] ?? '';
		$obData->conteudo = $postVars['conteudo'] ?? '';
		$obData->atualizar();

		} else {

		//NOVA INSTANCIA
		$obData = new EntityContratos;
		$obData->nome = $postVars['nome'] ?? '';
		$obData->conteudo = $postVars['conteudo'] ?? '';
		$obData->id_admin = $id_admin;
		$obData->cadastrar();
}

		if($obData){
			return true;
		} else {
			return 'Erro ao cadastrar contratos';
		}
		

	}


	public static function deleteContrato($request){

		$postVars = $request->getPostVars();
		$id = (int)($postVars['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();

		if (!TenantHelper::pertence('contratos', $id, $id_admin)) {
			return 'Registro não encontrado.';
		}

		//NOVA INSTANCIA
		$obData = new EntityContratos;
		$obData->id = $id;
		$obData->excluir();

		if($obData){
			return true;
		} else {
			return 'Erro ao excluir esse contrato';
		}
		
	}

}