<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class Certificados{

	public 
	$id,
	$id_aluno,
	$id_trilha,
	$id_admin,
	$modulos,
	$carga_h,
	$codigo,
	$conclusao;

	//RETORNA COM BASE NO ID
	public static function getCertificadoById($id){

		return self::getCertificados('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('certificados');
		$this->id = $obDatabase->insert([
			'id_aluno' => $this->id_aluno,
			'id_trilha' => $this->id_trilha,
			'id_admin' => $this->id_admin,
			'carga_h' => $this->carga_h,
			'modulos' => $this->modulos,
			'codigo' => ($this->codigo) ? $this->codigo : bin2hex(random_bytes(8)),
			'conclusao' => $this->conclusao
			
		]);
		
		return true;
	} 

	//RETORNA A INFORMAÇÃO
	public static function getCertificados($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){

		return (new Database('certificados'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	//ATUALIZA NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('certificados'))->update('id = '.$this->id,[
			'id_aluno' => $this->id_aluno,
			'id_trilha' => $this->id_trilha,
			'carga_h' => $this->carga_h,
			'modulos' => $this->modulos,
			'conclusao' => $this->conclusao
		]);

	}

	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

		return (new Database('certificados'))->delete('id = '.$this->id);

	}

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'certificados'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return \PDOStatement|false */
	public static function listByAluno(int $idAluno, int $idAdmin) {
		if (!self::tabelaExiste() || $idAluno <= 0) {
			return false;
		}
		$where = 'certificados.id_aluno = '.(int)$idAluno;
		if ($idAdmin > 0) {
			$where .= ' AND certificados.id_admin = '.(int)$idAdmin;
		}
		return self::getCertificados($where, 'certificados.id DESC');
	}

}