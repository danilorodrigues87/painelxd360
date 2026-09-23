<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class Trilhas{

	public $id,
	$nome,
	$id_categoria,
	$carga_h,
	$descricao,
	$valor_mensal,
	$site,
	$ativo = 1,
	$img;

	public static function temColunaAtivo(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM `trilhas` LIKE 'ativo'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	//RETORNA COM BASE NO ID
	public static function getTrilhaById($id){

		return self::getTrilha('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('trilhas');
		$dados = [
			'nome' => $this->nome,
			'id_categoria' => $this->id_categoria,
			'carga_h' => $this->carga_h,
			'id_admin' => $this->id_admin,
			'descricao' => $this->descricao,
			'valor_mensal' => $this->valor_mensal,
			'site' => $this->site,
			'img' => $this->img
		];
		if (self::temColunaAtivo()) {
			$dados['ativo'] = (int)($this->ativo ?? 1);
		}
		$this->id = $obDatabase->insert($dados);
		
		return true;
	} 

	//RETORNA A INFORMAÇÃO
	public static function getTrilha(
    $where = null,
    $order = null,
    $limit = null,
    $fields = '*',
    $innerJoin = null,
    $group = null
){
    return (new Database('trilhas'))->select(
        $where,
        $order,
        $limit,
        $fields,
        $innerJoin,
        $group
    );
}


	//RETORNA A INFORMAÇÃO
	public static function getCustomTrilha($where = null){

		return (new Database())->customSelect($where);
	}

	//ATUALIZA NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		$dados = [
			'nome' => $this->nome,
			'id_categoria' => $this->id_categoria,
			'carga_h' => $this->carga_h,
			'descricao' => $this->descricao,
			'valor_mensal' => $this->valor_mensal,
			'site' => $this->site,
			'img' => $this->img
		];
		if (self::temColunaAtivo()) {
			$dados['ativo'] = (int)($this->ativo ?? 1);
		}
		return (new Database('trilhas'))->update('id = '.$this->id, $dados);

	}

	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

		return (new Database('trilhas'))->delete('id = '.$this->id);

	}

}