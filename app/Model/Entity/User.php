<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class User{

	public $id,
	$nome,
	$email,
	$senha,
	$nivel,
	$id_admin,
	$ativo,
	$whatsapp,
	$id_responsavel=0,
	$foto,
	$code,
	$recCode,
	$acesso;

	public static function temColunaFoto(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('usuarios'))->execute(
				"SHOW COLUMNS FROM usuarios LIKE 'foto'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasTermosVersao(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('usuarios'))->execute(
				"SHOW COLUMNS FROM usuarios LIKE 'termos_versao'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	//RETORNA UM USUÁRIO COM BASE NO EMAIL
	public static function getUserByEmail($email){
		return (new Database('usuarios'))->select('email = "'.$email.'"')->fetchObject(self::class);
	}

	//RETORNA UM USUÁRIO PELO E-MAIL (case-insensitive)
	public static function getUserByEmailNormalized(string $email): ?self {
		$email = strtolower(trim($email));
		if ($email === '') {
			return null;
		}
		$row = (new Database('usuarios'))->select('LOWER(email) = "'.addslashes($email).'"')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function temColunaRecCode(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('usuarios'))->execute(
				"SHOW COLUMNS FROM usuarios LIKE 'recCode'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	//RETORNA UM USUÁRIO COM BASE NO CÓDIGO DE RECUPERAÇÃO DE SENHA
	public static function getUserByCode($recCode){
		return (new Database('usuarios'))->select('recCode = "'.$recCode.'"')->fetchObject(self::class);
	}	

	//RETORNA UM DEPOIMENTO COM BASE NO ID
	public static function getUserById($id){

		return self::getUser('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA A MENSAGEM PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('usuarios');
		$dados = [
			'nome' => $this->nome,
			'id_responsavel' => $this->id_responsavel,
			'email' => $this->email,
			'nivel' => $this->nivel,
			'senha' => $this->senha,
			'whatsapp' => $this->whatsapp ?? '',
			'rg' => $this->rg ?? '',
			'cpf' => $this->cpf ?? '',
			'nascimento' => $this->nascimento ?? null,
			'endereco' => $this->endereco ?? '',
			'numero' => $this->numero ?? '',
			'bairro' => $this->bairro ?? '',
			'uf' => $this->uf ?? 0,
			'cidade' => $this->cidade ?? 0,
			'ativo' => $this->ativo,
			'acesso' => $this->acesso,
			'id_admin' => $this->id_admin
		];
		if (self::temColunaFoto()) {
			$dados['foto'] = $this->foto ?: null;
		}
		$this->id = $obDatabase->insert($dados);
		
		return true;
	} 

	//RETORNA DEPOIMENTOS
	public static function getUser($where = null,$order = null,$limit = null,$fields = '*'){

		return (new Database('usuarios'))->select($where,$order,$limit,$fields);
	}

	//ATUALIZA A MENSAGEM NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		$dados = [
			'nome' => $this->nome,
			'email' => $this->email,
			'nivel' => $this->nivel,
			'id_responsavel' => $this->id_responsavel,
			'whatsapp' => $this->whatsapp ?? '',
			'rg' => $this->rg ?? '',
			'cpf' => $this->cpf ?? '',
			'nascimento' => $this->nascimento ?? null,
			'endereco' => $this->endereco ?? '',
			'numero' => $this->numero ?? '',
			'bairro' => $this->bairro ?? '',
			'uf' => $this->uf ?? 0,
			'cidade' => $this->cidade ?? 0,
			'ativo' => $this->ativo,
			'acesso' => $this->acesso
		];
		if (self::temColunaFoto()) {
			$dados['foto'] = $this->foto ?: null;
		}
		return (new Database('usuarios'))->update('id = '.$this->id, $dados);

	}


	//ATUALIZA A MENSAGEM NO BANCO
	public function atualizaPerfil(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		$dados = [
			'nome' => $this->nome,
			'email' => $this->email,
			'whatsapp' => $this->whatsapp,
			'rg' => $this->rg,
			'cpf' => $this->cpf,
			'nascimento' => $this->nascimento,
			'endereco' => $this->endereco,
			'numero' => $this->numero,
			'bairro' => $this->bairro,
			'uf' => $this->uf,
			'cidade' => $this->cidade
		];
		if (self::temColunaFoto()) {
			$dados['foto'] = $this->foto ?: null;
		}
		return (new Database('usuarios'))->update('id = '.$this->id, $dados);

	}


	//ATUALIZA ACEITE DOS TERMOS
	public function termoAceito(){
		$dados = [
			'termos_uso' => $this->termos_uso
		];
		if (self::temColunasTermosVersao()) {
			$dados['termos_versao'] = $this->termos_versao ?? null;
			$dados['termos_aceito_em'] = $this->termos_aceito_em ?? date('Y-m-d H:i:s');
		}
		return (new Database('usuarios'))->update('id = '.$this->id, $dados);
	}

	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

		return (new Database('usuarios'))->delete('id = '.$this->id);

	}

	public function atualizarResponsavel() {

    // ATUALIZA RESPONSÁVEIS
		return (new Database('usuarios'))->update('id = '.$this->id,[
			'id_responsavel' => $this->id_responsavel
		]);
	}


	//RESETAR SENHA DO USUÁRIO
	public function resetSenha(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('usuarios'))->update('id = '.$this->id,[
			'senha' => $this->senha
		]);

	}

	//ENVIA O CODIGO DE RECUPERAÇÃO PARA O BANCO DE DADOS
	public function setRecCode(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('usuarios'))->update('id = '.$this->id,[
			'recCode' => $this->code
		]);

	}

	public function clearRecCode(){
		return (new Database('usuarios'))->update('id = '.$this->id, [
			'recCode' => null,
		]);
	}

}
