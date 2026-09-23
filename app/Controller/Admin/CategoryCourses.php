<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\CategoryCourses as Category_Courses;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Common\Helpers\ContratoClausulaHelper;
use \App\Common\Helpers\ContratoTemplateHelper;
use \App\Common\Helpers\ContratoVariaveisBuilder;
use \App\Model\Entity\ClientesAssinantes;
use \App\Session\User\Login as SessionUser;

class CategoryCourses extends Page{

	private static function redirectPainel($request, string $route): void {
		if ($request instanceof \App\Http\Request) {
			$router = $request->getRouter();
			if ($router) {
				$router->redirect($route);
			}
		}
		header('Location: '.URL.'/'.ltrim($route, '/'));
		exit;
	}

	private static function extrairIdCategoriaContrato($request): int {
		if (!$request instanceof \App\Http\Request) {
			return 0;
		}
		$uri = (string)$request->getUri();
		if (preg_match('#/painel/categoria/cursos/contrato/(\d+)#', $uri, $m)) {
			return (int)$m[1];
		}
		return 0;
	}

	private static function assertContratoAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				self::redirectPainel($request, '/painel');
			}
			return false;
		}
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		if (!in_array('contratos', ModuleGateHelper::getSlugsEscola($idAdmin), true)) {
			if (!$api) {
				self::redirectPainel($request, '/painel');
			}
			return false;
		}
		return true;
	}

	private static function podeEditarContratoCategoria(): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			return false;
		}
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		return in_array('contratos', ModuleGateHelper::getSlugsEscola($idAdmin), true);
	}

	//RETORNA O FORMULARIO
	public static function index($request){
		$content = View::render('admin/modules/categoria_cursos/index', [
			'mostrar_contrato' => self::podeEditarContratoCategoria(),
			'coluna_contrato_ok' => Category_Courses::temColunaContrato(),
		]);

		//RETORNA A PÁGINA COMPLETA
		/**
         * TITULO DA PAGINA
         * CONTEUDO
         * CURRENTSESSION SESSÃO ATUAL
         * REQUEST SE NESCESSÁRIO
         */
		return parent::getPanel('Categorias',$content,'pedagogico');
	}

	private static function getCategoryItens($request,&$obPagination){

		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];

		$itens = '<button type="button" class="btn btn-success" onclick="list_itens(\'\',\'novo\')" data-toggle="modal">Nova Categoria</button>';

		//QUANTIDADE TOTAL DE REGISTROS
		$quantidadeTotal = Category_Courses::getCategory('id_admin = ' . (int)$id_admin,null,null,'COUNT(*) as qtd')->fetchObject()->qtd;

		//PAGINA ATUAL
		$queryParams = $request->getPostVars();
		$paginaAtual = $queryParams['page'] ?? 1;

		//INSTANCIA DE PAGINAÇÃO
		$obPagination = new Pagination($quantidadeTotal,$paginaAtual,5);

		// -=-=-=-    NÃO TERMINEI    -=-=-=-==-=-=  /// -=-=-=-=-=-=

		$innerJoin = 'INNER JOIN categorias_curso ON trilhas.id_categoria = categorias_curso.id';

		$fields = 'trilhas.id, trilhas.nome as trilha, categorias_curso.nome as categoria, trilhas.carga_h';

		//RESULTADOS DA PAGINA
		$results = Category_Courses::getCategory('id_admin = ' . (int)$id_admin, 'nome ASC', $obPagination->getLimit());

		$podeContrato = self::podeEditarContratoCategoria();
		$colContratoOk = Category_Courses::temColunaContrato();

		//REDERIZA O ITEM
		while ($obUsers = $results->fetchObject(Category_Courses::class)) {

			$badgeContrato = '—';
			if ($podeContrato && $colContratoOk) {
				$ok = Category_Courses::contratoEstaCompleto($obUsers);
				$badgeContrato = $ok
					? '<span class="badge bg-success">Contrato OK</span>'
					: '<span class="badge bg-warning text-dark">Incompleto</span>';
			} elseif ($podeContrato) {
				$badgeContrato = '<span class="badge bg-secondary">Aguardando SQL</span>';
			}

			$linkContrato = $podeContrato
				? '<li><a class="dropdown-item" href="'.URL.'/painel/categoria/cursos/contrato/'.$obUsers->id.'"><i class="fas fa-file-contract"></i> Editar contrato</a></li>'
				: '';

			$btnContrato = $podeContrato
				? ' <a class="btn btn-sm btn-outline-primary ms-1" href="'.URL.'/painel/categoria/cursos/contrato/'.$obUsers->id.'" title="Editar contrato"><i class="fas fa-file-contract"></i></a>'
				: '';

			$itens .= '<tr>
			<td>'.$obUsers->nome.'</td>
			<td>'.$obUsers->descricao.'</td>
			<td>'.$badgeContrato.'</td>
			<td>
			<div class="dropdown d-inline-block">
			<button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
			<i class="far fa-edit fa-lg"></i>
			</button>
			<ul class="dropdown-menu">
			<li>
			<a class="dropdown-item" href="#" onclick="list_itens('.$obUsers->id.', \'editar\')"><i class="far fa-edit fa-lg"></i> Editar</a>
			</li>
			'.$linkContrato.'
			<li>
			<a class="dropdown-item" href="#" onclick="excluir('.$obUsers->id.')" ><i class="far fa-trash-alt fa-lg"></i> Excluir</a>
			</li>
			</ul>
			</div>'.$btnContrato.'
			</td>
			</tr>';

		}

 
		$table = '<div class="card-body">
		<div class="table-responsive">
		<table class="table table-striped" id="dataTable" width="100%" cellspacing="0">
		<thead>
		<tr>
		<th>Nome</th>
		<th>Descricão</th>
		<th>Contrato</th>
		<th>Ações</th>
		</tr>
		</thead>
		<tbody>'.$itens.'</tbody>
		</table>
		</div>
		</div>';

		if ($podeContrato && !$colContratoOk) {
			$table = '<div class="alert alert-warning small mx-3 mt-3">'
				.'Para editar cláusulas por categoria, execute o SQL '
				.'<code>database/categorias_contrato.sql</code> no phpMyAdmin. '
				.'O link <strong>Editar contrato</strong> já está disponível abaixo.</div>'.$table;
		}

		//RETORNA
		return $table;
	}

	public static function getInfo($request){


	//CONTEÚDO 
		$conteudo = [
			'itens' => self::getCategoryItens($request,$obPagination),
			'pagination' => parent::getPagination($request,$obPagination)
		];

		return parent::jsonLista($conteudo);

	}

	private static function getForm($request) {
		$postVars = $request->getPostVars();

		if ($postVars['funcao'] == 'editar') {
			$id = (int)($postVars['id'] ?? 0);
			$id_admin = parent::getIdAdminInt();
			if (!TenantHelper::pertence('categorias_curso', $id, $id_admin)) {
				return json_encode(['erro' => 'Registro não encontrado.']);
			}
			$dados = (array) Category_Courses::getCategoryById($id);
		}

		$form = '<form id="form" method="post">
		<div class="modal-header">
		<h1 class="modal-title fs-5" id="exampleModalLabel">Categoria</h1>
		<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		</div>
		<div class="modal-body">
		<div id="response"></div>
		<div class="form-group">
		<label>Nome</label>
		<input type="text" name="nome" value="' . @$dados['nome'] . '" class="form-control" required>
		</div>
		<div class="form-group my-3">
		<label>Descrição</label>
		<input type="descricao" name="descricao" value="' . @$dados['descricao'] . '" class="form-control" required>
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


	public static function getNewCategory($request){

		$form = self::getForm($request);
		return json_encode($form);
	}

	public static function setNewCategory($request) {

    // DADOS DO ADMIN
    $id_admin = parent::getIdAdmin()['usuario']['id_admin'];
    $postVars = $request->getPostVars();

    // Filtrando os valores de entrada
    $nome = filter_var($postVars['nome'] ?? '', FILTER_SANITIZE_STRING);
    $descricao = filter_var($postVars['descricao'] ?? '', FILTER_SANITIZE_STRING);

    // Array de resposta padrão
    $resposta = ["filtro" => null];

    // Verificação de ID para atualizar ou cadastrar nova categoria
    if (!empty($postVars['id'])) {
        $id = (int)$postVars['id'];
        if (!TenantHelper::pertence('categorias_curso', $id, (int)$id_admin)) {
            $resposta['erro'] = 'Registro não encontrado.';
            return json_encode($resposta);
        }

        // Instância para atualização
        $obData = new Category_Courses;
        $obData->id = $id;
        $obData->nome = $nome;
        $obData->descricao = $descricao;

        // Executa a atualização
        $obData->atualizar();

    } else {
        // Instância para cadastro
        $obData = new Category_Courses;
        $obData->nome = $nome;
        $obData->descricao = $descricao;
        $obData->id_admin = $id_admin;

        // Executa o cadastro
        $obData->cadastrar();
    }

    // Verifica sucesso da operação e define resposta
    if (!$obData) {
        $resposta["erro"] = 'Erro ao cadastrar categoria';
    }

    return json_encode($resposta);
}



	public static function deleteCategory($request){

		$postVars = $request->getPostVars();
		$id = (int)($postVars['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();

		if (!TenantHelper::pertence('categorias_curso', $id, $id_admin)) {
			return 'Registro não encontrado.';
		}

		//NOVA INSTANCIA
		$obData = new Category_Courses;
		$obData->id = $id;
		$obData->excluir();

		if($obData){
			return true;
		} else {
			return 'Erro ao excluir essa categoria';
		}
		
	}

	public static function contratoIndex($request) {
		if (!self::assertContratoAcesso($request)) {
			return '';
		}
		$id = self::extrairIdCategoriaContrato($request);
		$idAdmin = parent::getIdAdminInt();
		if ($id <= 0 || !TenantHelper::pertence('categorias_curso', $id, $idAdmin)) {
			self::redirectPainel($request, '/painel/categoria/cursos');
			return '';
		}
		$cat = Category_Courses::getCategoryById($id);
		if (!$cat instanceof Category_Courses) {
			self::redirectPainel($request, '/painel/categoria/cursos');
			return '';
		}
		$content = View::render('admin/modules/categoria_cursos/contrato', [
			'id_categoria' => $id,
			'nome_categoria' => (string)$cat->nome,
		]);
		return parent::getPanel('Categorias', $content, 'pedagogico', $request);
	}

	public static function contratoApi($request) {
		if (!self::assertContratoAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}
		$id = self::extrairIdCategoriaContrato($request);
		$idAdmin = parent::getIdAdminInt();
		if ($id <= 0 || !TenantHelper::pertence('categorias_curso', $id, $idAdmin)) {
			return json_encode(['success' => false, 'message' => 'Categoria não encontrada.']);
		}

		if (!$request instanceof \App\Http\Request) {
			return json_encode(['success' => false, 'message' => 'Requisição inválida.']);
		}

		$postVars = $request->getPostVars();
		$acao = $postVars['acao'] ?? '';

		if ($acao === 'carregar') {
			return self::contratoCarregar($id);
		}
		if ($acao === 'salvar') {
			return self::contratoSalvar($id, $postVars);
		}
		if ($acao === 'preview') {
			return self::contratoPreview($id, $postVars);
		}
		if ($acao === 'modelos') {
			return json_encode([
				'success' => true,
				'modelos' => ContratoClausulaHelper::modelosSugeridos(),
			], JSON_UNESCAPED_UNICODE);
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function contratoCarregar(int $id): string {
		$cat = Category_Courses::getCategoryById($id);
		if (!$cat instanceof Category_Courses) {
			return json_encode(['success' => false, 'message' => 'Categoria não encontrada.']);
		}
		$row = Category_Courses::rowToContratoArray($cat);
		$tokens = [];
		foreach (ContratoClausulaHelper::catalogoTokens() as $k => $desc) {
			$tokens[] = ['chave' => $k, 'descricao' => $desc];
		}
		return json_encode([
			'success'           => true,
			'coluna_ok'         => Category_Courses::temColunaContrato(),
			'nome'              => (string)$cat->nome,
			'clausulas'         => $row,
			'contrato_completo' => Category_Courses::contratoEstaCompleto($cat),
			'tokens'            => $tokens,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function contratoSalvar(int $id, array $postVars): string {
		if (!Category_Courses::temColunaContrato()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL database/categorias_contrato.sql no phpMyAdmin.',
			]);
		}
		$dados = [
			'contrato_clausula_1'          => (string)($postVars['contrato_clausula_1'] ?? ''),
			'contrato_clausula_2'          => (string)($postVars['contrato_clausula_2'] ?? ''),
			'contrato_clausula_3'          => (string)($postVars['contrato_clausula_3'] ?? ''),
			'contrato_clausula_extra'      => (string)($postVars['contrato_clausula_extra'] ?? ''),
			'contrato_pagamento_parcelado' => (string)($postVars['contrato_pagamento_parcelado'] ?? ''),
			'contrato_pagamento_vista'     => (string)($postVars['contrato_pagamento_vista'] ?? ''),
			'contrato_pagamento_bolsista'  => (string)($postVars['contrato_pagamento_bolsista'] ?? ''),
			'contrato_obs_pontualidade'    => (string)($postVars['contrato_obs_pontualidade'] ?? ''),
		];
		if (!Category_Courses::salvarContratoClausulas($id, $dados)) {
			return json_encode(['success' => false, 'message' => 'Falha ao salvar.']);
		}
		return json_encode([
			'success' => true,
			'message' => 'Cláusulas da categoria salvas.',
		]);
	}

	private static function contratoPreview(int $id, array $postVars): string {
		$user = SessionUser::getUserLogedData();
		$escolaSession = $user['escola'] ?? [];
		if (!is_array($escolaSession)) {
			$escolaSession = [];
		}

		$idAdmin = TenantHelper::getIdAdmin();
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		$html = ContratoTemplateHelper::resolverModelo($escola instanceof ClientesAssinantes ? $escola : null);

		$override = [
			'contrato_clausula_1'          => (string)($postVars['contrato_clausula_1'] ?? ''),
			'contrato_clausula_2'          => (string)($postVars['contrato_clausula_2'] ?? ''),
			'contrato_clausula_3'          => (string)($postVars['contrato_clausula_3'] ?? ''),
			'contrato_clausula_extra'      => (string)($postVars['contrato_clausula_extra'] ?? ''),
			'contrato_pagamento_parcelado' => (string)($postVars['contrato_pagamento_parcelado'] ?? ''),
			'contrato_pagamento_vista'     => (string)($postVars['contrato_pagamento_vista'] ?? ''),
			'contrato_pagamento_bolsista'  => (string)($postVars['contrato_pagamento_bolsista'] ?? ''),
			'contrato_obs_pontualidade'    => (string)($postVars['contrato_obs_pontualidade'] ?? ''),
		];

		$opts = [
			'id_categoria'        => $id,
			'menor'               => !empty($postVars['menor']),
			'pagamento'           => (string)($postVars['pagamento'] ?? 'parcelado'),
			'clausulas_override'  => $override,
		];
		$vars = ContratoVariaveisBuilder::dadosExemplo($escolaSession, $opts);
		$render = ContratoTemplateHelper::aplicar($html, $vars);

		return json_encode([
			'success' => true,
			'preview' => $render,
		], JSON_UNESCAPED_UNICODE);
	}

}