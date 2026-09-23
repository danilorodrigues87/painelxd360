<?php 

namespace App\Controller\Admin;
use \App\Utils\View;
use \App\Model\Entity\Trilhas as EntityTrilhas;
use \App\Model\Entity\CategoryCourses as Category_Courses;
use \App\Model\Db\Pagination;
use \App\Common\Upload;
use \App\Common\Helpers\NumeroHelper;
use \App\Common\Helpers\TenantHelper;

class Trilhas extends Page{

	//RETORNA O FORMULARIO
	public static function index($request){
		//CONTEÚDO DE FORMULÁRIO
		$content = View::render('admin/modules/trilhas/index',[]);

		//RETORNA A PÁGINA COMPLETA
		/**
         * TITULO DA PAGINA
         * CONTEUDO
         * CURRENTSESSION SESSÃO ATUAL
         * REQUEST SE NESCESSÁRIO
         */
		return parent::getPanel('Trilhas',$content,'pedagogico');
	}

	private static function getTrilhaItens($request,&$obPagination){

		//DADOS DO ADMIN
		$id_admin = parent::getIdAdmin()['usuario']['id_admin'];
		
		$queryParams = $request->getPostVars();
		$busca = trim((string)($queryParams['busca'] ?? ''));
		$idCategoria = (int)($queryParams['id_categoria'] ?? 0);
		$filtroAtivo = (string)($queryParams['ativo'] ?? 'todos');

		$where = 'trilhas.id_admin = '.(int)$id_admin;
		if ($busca !== '') {
			$where .= " AND trilhas.nome LIKE '%".addslashes($busca)."%'";
		}
		if ($idCategoria > 0) {
			$where .= ' AND trilhas.id_categoria = '.$idCategoria;
		}
		if (EntityTrilhas::temColunaAtivo() && ($filtroAtivo === '1' || $filtroAtivo === '0')) {
			$where .= ' AND trilhas.ativo = '.(int)$filtroAtivo;
		}

		$optCat = '<option value="0">Todas categorias</option>';
		$cats = Category_Courses::getCategory('id_admin = '.(int)$id_admin, 'nome ASC');
		while ($c = $cats->fetchObject(Category_Courses::class)) {
			$sel = $idCategoria === (int)$c->id ? ' selected' : '';
			$optCat .= '<option value="'.(int)$c->id.'"'.$sel.'>'.htmlspecialchars((string)$c->nome, ENT_QUOTES, 'UTF-8').'</option>';
		}

		$filtros = '<div class="row g-2 mb-3 align-items-end">
			<div class="col-md-4">
				<label class="form-label small mb-0">Busca</label>
				<input type="text" class="form-control form-control-sm" id="filtro-trilha-busca" value="'.htmlspecialchars($busca, ENT_QUOTES, 'UTF-8').'" placeholder="Nome da trilha">
			</div>
			<div class="col-md-3">
				<label class="form-label small mb-0">Categoria</label>
				<select class="form-select form-select-sm" id="filtro-trilha-categoria">'.$optCat.'</select>
			</div>
			<div class="col-md-3">
				<label class="form-label small mb-0">Status</label>
				<select class="form-select form-select-sm" id="filtro-trilha-ativo">
					<option value="todos"'.($filtroAtivo === 'todos' ? ' selected' : '').'>Todos</option>
					<option value="1"'.($filtroAtivo === '1' ? ' selected' : '').'>Ativas</option>
					<option value="0"'.($filtroAtivo === '0' ? ' selected' : '').'>Inativas</option>
				</select>
			</div>
			<div class="col-md-2">
				<button type="button" class="btn btn-sm btn-primary w-100" id="btn-filtrar-trilhas">Filtrar</button>
			</div>
		</div>';

		$itens = '<button type="button" class="btn btn-success mb-2" onclick="list_itens(\'\',\'novo\')" data-toggle="modal">Nova Trilha</button>'.$filtros;

		//QUANTIDADE TOTAL DE REGISTROS
		$quantidadeTotal = EntityTrilhas::getTrilha($where,null,null,'COUNT(*) as qtd')->fetchObject()->qtd;

		//PAGINA ATUAL
		$paginaAtual = $queryParams['page'] ?? 1;

		//INSTANCIA DE PAGINAÇÃO
		$obPagination = new Pagination($quantidadeTotal,$paginaAtual,5);

		$innerJoin = 'INNER JOIN categorias_curso ON trilhas.id_categoria = categorias_curso.id';

		$fields = 'trilhas.id,trilhas.img, trilhas.nome as trilha, categorias_curso.nome as categoria, trilhas.carga_h';
		if (EntityTrilhas::temColunaAtivo()) {
			$fields .= ', trilhas.ativo';
		}

		//RESULTADOS DA PAGINA
		$results = EntityTrilhas::getTrilha($where, 'trilhas.id DESC', $obPagination->getLimit(),$fields,$innerJoin);

// Defina a base da URL uma única vez fora do loop
$caminhoImg = URL . '/uploads/img/site/curso/';

while ($obDados = $results->fetchObject(EntityTrilhas::class)) {
    
    // Simplificação: se img não for vazio, use ela, caso contrário use a padrão
    // O trim remove espaços e o (string) garante que valores nulos não quebrem a lógica
    $imgExibe = (!empty(trim((string)$obDados->img))) 
                ? $caminhoImg . $obDados->img 
                : $caminhoImg . 'sem-foto.png';

	$badgeAtivo = '';
	if (EntityTrilhas::temColunaAtivo()) {
		$badgeAtivo = ((int)($obDados->ativo ?? 1) === 1)
			? '<span class="badge bg-success">Ativa</span>'
			: '<span class="badge bg-secondary">Inativa</span>';
	}

    $itens .= '<tr>
                <td>
                    <img src="' . $imgExibe . '" alt="Capa da Trilha" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">
                </td>
			<td>'.$obDados->trilha.'</td>
			<td>'.$obDados->categoria.'</td>
			<td>'.$obDados->carga_h.'</td>
			<td>'.$badgeAtivo.'</td>
			<td>
			<div class="dropdown">
			<button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
			<i class="far fa-edit fa-lg"></i>
			</button>
			<ul class="dropdown-menu">
			<li>
			<a class="dropdown-item" href="#" onclick="list_itens('.$obDados->id.', \'editar\')"><i class="far fa-edit fa-lg"></i> Editar</a>
			</li>
			<li>
			<a class="dropdown-item" href="#" onclick="excluir('.$obDados->id.')" ><i class="far fa-trash-alt fa-lg"></i> Excluir</a>
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
		<th>Foto</th>
		<th>Nome</th>
		<th>Categoria</th>
		<th>Carga Horária</th>
		<th>Status</th>
		<th>Ações</th>
		</tr>
		</thead>
		<tbody>'.$itens.'</tbody>
		</table>
		</div>
		</div>
		<script>
		(function(){
			if (window.__trilhasFiltroBound) return;
			window.__trilhasFiltroBound = true;
			$(document).on("click", "#btn-filtrar-trilhas", function(){
				if (typeof listagem === "undefined") return;
				$.ajax({
					url: url_base + listagem,
					method: "POST",
					dataType: "json",
					data: {
						page: 1,
						busca: $("#filtro-trilha-busca").val() || "",
						id_categoria: $("#filtro-trilha-categoria").val() || 0,
						ativo: $("#filtro-trilha-ativo").val() || "todos"
					}
				}).done(function(res){
					if (res && res.itens) $("#listar").html(res.itens);
					if (res && res.pagination) $("#pagination").html(res.pagination);
				});
			});
		})();
		</script>';

		//RETORNA
		return $table;
	}

	public static function getInfo($request){


	//CONTEÚDO 
		$conteudo = [
			'itens' => self::getTrilhaItens($request,$obPagination),
			'pagination' => parent::getPagination($request,$obPagination)
		];

		return parent::jsonLista($conteudo);


	}
private static function getForm($request) {

// Usando aspas duplas no PHP para permitir aspas simples no JS
$scriptJs = "<script>
    $(document).ready(function() {
        $('#input-img').change(function() {
            const file = this.files[0];
            if (file) {
                let reader = new FileReader();
                reader.onload = function(event) {
                    $('#preview-img').attr('src', event.target.result);
                };
                reader.readAsDataURL(file);
            }
        });
    });

     ClassicEditor
    .create(document.querySelector('#editor'), {
        language: 'pt-br',
        toolbar: [
            'heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', 'undo', 'redo'
        ],
        // Adicione esta configuração para remover o erro:
        image: {
            toolbar: [
                'imageStyle:inline',
                'imageStyle:block',
                'imageStyle:side',
                '|',
                'toggleImageCaption',
                'imageTextAlternative'
            ]
        }
    })
    .then(editor => {
        meuEditor = editor;
    })
    .catch(error => {
        console.error(error);
    });

</script>

<style>
.ck-editor__editable_inline {
    min-height: 200px;
    max-height: 300px; /* Se o texto passar disso, aparece o scroll */
    overflow-y: auto;
}

</style>
";


    $postVars = $request->getPostVars();

    // Verifica se a função é 'editar' e carrega os dados correspondentes
    $dados = [];
    if (isset($postVars['funcao']) && $postVars['funcao'] == 'editar') {
        $id = (int)($postVars['id'] ?? 0);
        $id_admin = parent::getIdAdminInt();
        if (!TenantHelper::pertence('trilhas', $id, $id_admin)) {
            return json_encode(['erro' => 'Registro não encontrado.']);
        }
        $dados = (array) EntityTrilhas::getTrilhaById($id);
    }

    // DADOS DO ADMIN
    $id_admin = parent::getIdAdmin()['usuario']['id_admin'];

    $results = Category_Courses::getCategory('id_admin = ' . (int)$id_admin, 'id ASC');

    // Carrega o SELECT
    $optionSelect = '';
    while ($obDados = $results->fetchObject(Category_Courses::class)) {
        $selected = (isset($dados['id_categoria']) && $dados['id_categoria'] == $obDados->id) ? 'selected' : '';
        $optionSelect .= '
        <option ' . $selected . ' value="' . htmlspecialchars($obDados->id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($obDados->nome, ENT_QUOTES, 'UTF-8') . '</option>
        ';
    }

    // Lógica da Imagem: Definimos o caminho antes de montar o formulário
    $caminhoImg = URL . '/uploads/img/site/curso/';
	// 1. Pegamos o valor do banco ou uma string vazia se não existir
	$nomeImagem = isset($dados['img']) ? trim($dados['img']) : '';

    if ($nomeImagem !== '' && $nomeImagem !== null) {
    $imagemExibir = $caminhoImg . $nomeImagem;
	} else {
    $imagemExibir = $caminhoImg . 'sem-foto.png';
	}

    // Criação do formulário
    $form = '<form id="form" method="post" enctype="multipart/form-data">
    <div class="modal-header">
        <h1 class="modal-title fs-5" id="exampleModalLabel">Categoria</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <div id="response"></div>
        <div class="row">
            <div class="form-group col-md-12">
                <label>Nome</label>
                <input type="text" name="nome" value="' . (isset($dados['nome']) ? htmlspecialchars($dados['nome']) : '') . '" class="form-control" required>
            </div>
            
            <div class="form-group col-md-12">
    <label>Descrição</label>
    <textarea name="descricao" rows="5" id="editor" class="form-control">' . (isset($dados['descricao']) ? htmlspecialchars($dados['descricao']) : '') . '</textarea>
</div>
            <div class="form-group col-md-12">
                <label>Imagem</label>
                <input type="file" name="img" id="input-img" class="form-control" accept="image/*">
                <div class="row">
                <div class="mt-3 col-md-4">
                    <img id="preview-img" src="' . $imagemExibir . '" 
                         style="max-width: 200px; display: block; border: 1px solid #ddd; padding: 5px; border-radius: 5px;">
                </div>
                <div class="col-md-8">
                <div class="row my-3">
                <div class="form-group col-md-6">
    <label>Valor mensal</label>
    <input type="text"  name="valor_mensal" id="valor_mensal" value="' . (isset($dados['valor_mensal']) ? NumeroHelper::moedaBr($dados['valor_mensal']) : '') . '" class="form-control mascara-dinheiro" required>
</div>
    
    <div class="form-group col-md-6">
                <label>Carga Horária</label>
                <input type="text" name="carga_h" value="' . (isset($dados['carga_h']) ? htmlspecialchars($dados['carga_h']) : '') . '" class="form-control" required>
            </div>

           	
            <div class="form-group ">
                <label>Categoria</label>
                <select class="form-control" name="id_categoria">
                    ' . $optionSelect . '
                </select>                   
            </div>
             <div class="form-group form-check col-md-6 mt-3">
        <input type="checkbox" name="ativoSite" value="1" class="form-check-input" ' . (isset($dados['site']) && $dados['site'] == 1 ? 'checked' : '') . '>
        <label class="form-check-label">Ativo no site</label>
    </div>
             <div class="form-group form-check col-md-6 mt-3">
        <input type="checkbox" name="ativoTrilha" value="1" class="form-check-input" ' . ((!isset($dados['ativo']) || (int)$dados['ativo'] === 1) ? 'checked' : '') . '>
        <label class="form-check-label">Ativa para matrícula</label>
    </div>
            </div>
		</div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
		<input type="hidden" name="id" value="' . ($dados['id'] ?? '') . '">
		<input type="hidden" name="img_atual" value="' . ($dados['img'] ?? '') . '">
        <button type="button" id="btn-fechar" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
    </div>
    </form> '.$scriptJs;

    return $form;
}



	public static function getNewTrilha($request){

		$form = self::getForm($request);
		return json_encode($form);
	}
public static function setNewTrilha($request){
    
    // DADOS DO ADMIN
    $id_admin = parent::getIdAdmin()['usuario']['id_admin'];

    $fileVars = $request->getFileVars();
    $postVars = $request->getPostVars();

    $valorLimpo = filter_var($postVars['valor_mensal'] ?? 0, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND);
    
    $resposta = ["filtro" => null];

    // Inicializamos a variável de imagem com o que já existe no banco ou vazio
    $img = $postVars['img_atual'] ?? ''; 

    // Processamento do Upload (Novo ou Edição)
    if(isset($fileVars['img']) && !empty($fileVars['img']['name'])){
        $obUpload = new Upload($fileVars['img']);
        $obUpload->generateNewName();
        // Se for edição, passamos o nome da imagem antiga para deletar
        $imagemAntiga = ($postVars['id'] != '') ? $img : false;
        $obUpload->Upload('/img/site/curso/', false, $imagemAntiga);
        $img = $obUpload->getBasename();
    }

    // Instancia o objeto (Camada de persistência)
    $obData = new EntityTrilhas;
    
    // Mapeamento comum (tanto para insert quanto update)
    $obData->nome         = filter_var($postVars['nome'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $obData->descricao    = isset($postVars['descricao']) ? trim($postVars['descricao']) : '';
    $obData->id_categoria = (int)($postVars['id_categoria'] ?? 0);
    $obData->id_admin     = (int)$id_admin; // Limpo de caracteres invisíveis
    $obData->carga_h      = filter_var($postVars['carga_h'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $obData->site         = isset($postVars['ativoSite']) ? 1 : 0;
    $obData->ativo        = isset($postVars['ativoTrilha']) ? 1 : 0;
    $obData->valor_mensal = NumeroHelper::moedaEN($valorLimpo) ?: 0.00;
    $obData->img          = $img;

    if($postVars['id'] != ''){
        $id = (int)$postVars['id'];
        if (!TenantHelper::pertence('trilhas', $id, (int)$id_admin)) {
            $resposta['erro'] = 'Registro não encontrado.';
            return json_encode($resposta);
        }
        $obData->id = $id;
        $sucesso = $obData->atualizar();
    } else {
        $sucesso = $obData->cadastrar();
    }

    if(!$sucesso){
        $resposta["erro"] = 'Erro ao registrar os dados no banco.';
    }

    return json_encode($resposta);
}


	public static function deleteTrilha($request){

		$postVars = $request->getPostVars();
		$id = (int)($postVars['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();

		if (!TenantHelper::pertence('trilhas', $id, $id_admin)) {
			return 'Registro não encontrado.';
		}

		//NOVA INSTANCIA
		$obData = new EntityTrilhas;
		$obData->id = $id;
		$obData->excluir();

		if($obData){
			return true;
		} else {
			return 'Erro ao excluir essa trilha';
		}
		
	}

}