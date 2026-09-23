<?php 
namespace App\Common\Helpers;

use \App\Model\Entity\User as EntityUser;
use \App\Model\Entity\Responsaveis as EntityRes;
use \App\Model\Entity\Matriculas as EntityMatri;
use \App\Model\Entity\Caixa;
use \App\Model\Entity\Certificados as EntityCertificados;
use \App\Session\User\Login as SessionUser;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\NumeroHelper;
use PDO;
use Exception;

class Atualization {

    // Atributos de conexão com o banco de dados
	private static $banco = "cti";
	private static $host = "localhost";
	private static $usuario = "root";
	private static $senha = "";
	private static $pdo;

    // Método para inicializar a conexão com o banco de dados
	private static function initConnection() {
		if (self::$pdo === null) {
			try {
				self::$pdo = new PDO("mysql:dbname=" . self::$banco . ";host=" . self::$host . ";charset=utf8", self::$usuario, self::$senha);
				self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			} catch (Exception $e) {
				echo "Erro ao conectar com o banco de dados: " . $e->getMessage();
				exit;
			}
		}
	}

    // Método para atualizar o caixa
	public static function atualizaCaixa() {
		$msg = '';

    	// Aumenta o limite de execução para 0 (sem limite)
		set_time_limit(0);

    	// Inicializa a conexão com o banco de dados
		self::initConnection();

		try {
			$query = self::$pdo->query("SELECT * FROM caixa where carne != ''");
			$res = $query->fetchAll(PDO::FETCH_ASSOC);

			foreach ($res as $titulo) {
            // Nova instância de Caixa
				$obCaixa = new Caixa();
				$obCaixa->id_admin = 1;
				$obCaixa->descricao = $titulo['descricao'];
				$obCaixa->tipo_transacao = $titulo['entrada_saida'];
				$obCaixa->valor = $titulo['valor'];
				$obCaixa->vencimento = $titulo['vencimento'];
				$obCaixa->data_pagamento = $titulo['pagamento'];
				$obCaixa->ultima_alteracao = ($titulo['hora_pagamento']) ? $titulo['hora_pagamento'] : null;
				$obCaixa->referencia = 'registro plataforma antiga';
				$obCaixa->id_ref = $titulo['carne'];
				$obCaixa->status = ($titulo['status'] == 'Pago') ? 1 : 0;
				$obCaixa->tipo_pagamento = ($titulo['tipo']) ? $titulo['tipo'] : 'Pix';
				$obCaixa->valor_pago = ($titulo['valor_pago']) ? $titulo['valor_pago'] : 0;
				$obCaixa->txt_id = ($titulo['txtId']) ? $titulo['txtId'] : '';
				$obCaixa->pix_copia_cola = ($titulo['pixCopiaECola']) ? $titulo['pixCopiaECola'] : '';
				$obCaixa->lancarMovimentacao();

            // Mensagem de sucesso ou erro
				if ($obCaixa) {
					$msg .= 'Título ' . $titulo['descricao'] . ' cadastrado.<br>';
				} else {
					$msg .= 'Erro ao cadastrar título.<br>';
				}
			}
		} catch (Exception $e) {
			$msg .= 'Erro: ' . $e->getMessage() . '<br>';
		}

		return $msg;
	}


    // Método para atualizar alunos
	public static function atualizaAlunos() {
		$msg = '';

		$id_admin =1;

		// Aumenta o limite de execução para 0 (sem limite)
		set_time_limit(0);

        // Inicializa a conexão com o banco de dados
		self::initConnection();

		self::atualizaAdmin();

		try {
			$query = self::$pdo->query("SELECT * FROM alunos");
			$res = $query->fetchAll(PDO::FETCH_ASSOC);

			foreach ($res as $aluno) {
				$id_antigo_aluno = $aluno['id'];

				$obUsers = new EntityUser;
				$obUsers->id_x = $aluno['id'];
				$obUsers->nome = $aluno['nome'];
				$obUsers->id_responsavel = 0;
				$obUsers->email = $aluno['email'];
				$obUsers->nivel = 'Cliente';
				$obUsers->senha = password_hash('12345678', PASSWORD_DEFAULT);
				$obUsers->whatsapp = $aluno['telefone'];
				$obUsers->rg = $aluno['rg'];
				$obUsers->cpf = $aluno['cpf'];
				$obUsers->nascimento = $aluno['nasc'];
				$obUsers->endereco = $aluno['endereco'];
				$obUsers->bairro = '';
				$obUsers->numero = 0;
				$obUsers->uf = 26;
				$obUsers->cidade = 8607;
				$obUsers->ativo = 'n';
				$obUsers->acesso = '["home"]';
				$obUsers->id_admin = $id_admin;
				$obUsers->cadastrar();
				$id_aluno = $obUsers->id; 



				if (!$obUsers) {
					$msg .= 'Erro id '. $aluno['id'].' Aluno ' . $aluno['nome'] . '<br>';
				} 
			}
		} catch (Exception $e) {
			$msg .= 'Erro: ' . $e->getMessage() . '<br>';
		}

		$msg .= 'Concluido<br>';

		return $msg;
	}

	// Método para atualizar Responsaveis
	public static function atualizaResponsaveis() {
		$msg = '';

		$id_admin =1;

		// Aumenta o limite de execução para 0 (sem limite)
		set_time_limit(0);

        // Inicializa a conexão com o banco de dados
		self::initConnection();

		try {
			$query = self::$pdo->query("SELECT * FROM alunos where responssavel != ''");
			$res = $query->fetchAll(PDO::FETCH_ASSOC);

			foreach ($res as $aluno) {

                 // Cadastro do responsável
				$obUsers = new EntityRes;
				$obUsers->nome = $aluno['responssavel'];
				$obUsers->email = '';
				$obUsers->whatsapp = $aluno['telefone'];
				$obUsers->rg = $aluno['rg_res'];
				$obUsers->cpf = $aluno['cpf_res'];
				$obUsers->nascimento = $aluno['nasc_res'];
				$obUsers->id_admin = $id_admin;
				$obUsers->cadastrar();

                // Mensagem de sucesso ou erro
				if ($obUsers) {

					$id_responsavel = $obUsers->id;
					$obRes = new EntityUser;
					$obRes->id = $aluno['id'];
					$obRes->id_responsavel = $id_responsavel;
					$obRes->atualizarResponsavel();

					if(!$obRes){
						$msg .= 'Erro ao cadastrar ID do responsavel.<br>';
					}

				} else {
					$msg .= 'Erro ao cadastrar aluno.<br>';
				}
			}
		} catch (Exception $e) {
			$msg .= 'Erro: ' . $e->getMessage() . '<br>';
		}

		$msg .='Compluido';

		return $msg;
	}

	// Método para atualizar matriculas
	public static function atualizaMatriculas() {
		$msg = '';

		$id_admin = 1;

		// Aumenta o limite de execução para 0 (sem limite)
		set_time_limit(0);

        // Inicializa a conexão com o banco de dados
		self::initConnection();

		try {
            // Consulta principal para obter todos os registros de 'carnes'
			$query = self::$pdo->query("SELECT * FROM carnes");
			$res = $query->fetchAll(PDO::FETCH_ASSOC);

			foreach ($res as $carnes) {
				$pacote = $carnes['pacote'];

                // Preparar a consulta para obter o id do pacote
				$queryPacote = self::$pdo->prepare("SELECT id as id_pacote FROM pacotes WHERE nome = :nome_pacote");
				$queryPacote->bindValue(":nome_pacote", $pacote, PDO::PARAM_STR);
				$queryPacote->execute();

                // Obter o id do pacote
				$obPacote = $queryPacote->fetch(PDO::FETCH_ASSOC);

                // Verificar se a consulta retornou resultados
				if (!$obPacote) {
					$msg .= "Nenhum pacote encontrado para o nome: " . htmlspecialchars($pacote) . "<br>";
                    continue; // Pula para a próxima iteração do loop
                }

                $id_pacote = $obPacote['id_pacote'];

                if (empty($id_pacote)) {
                	$msg .= "ID do pacote vazio para o pacote: " . htmlspecialchars($pacote) . "<br>";
                    continue; // Pula para a próxima iteração do loop
                }

                // Criar uma nova instância de EntityMatri e preencher os dados
                $obMatricula = new EntityMatri();
                $obMatricula->id_x = $carnes['id_contrato'];
                $obMatricula->id_aluno = $carnes['alunos'];
                $obMatricula->id_admin = $id_admin;
                $obMatricula->id_responsavel = 0;
                $obMatricula->id_trilha = $id_pacote;
                $obMatricula->carga_horaria = $carnes['carga_h'];
                $obMatricula->modulos = $carnes['modulos'];
                $obMatricula->horarios = $carnes['horarios'];
                $obMatricula->dia_semana = $carnes['dia_semana'];
                $obMatricula->aulas_semanais = $carnes['aulas_semanais'];
                $obMatricula->valor = $carnes['valor'];
                $obMatricula->qtd_parcelas = $carnes['qtd'];
                $obMatricula->dia_vencimento = $carnes['vence'];
                $obMatricula->primeiro_mes = $carnes['primeiromes'];
                $obMatricula->primeiro_ano = $carnes['primeiroano'];
                $obMatricula->tipo_parcelamento = ''; // Ajuste conforme necessário
                $obMatricula->desconto_pontualidade = $carnes['pontualidade'];
                $obMatricula->inicio = $carnes['inicio'];
                $obMatricula->fim = $carnes['final'];
                $obMatricula->status = $carnes['encerrado'];


                try {
                	$obMatricula->matricular();
                	$msg .= 'ID antigo' . $carnes['id_contrato'] .' id novo'.$obMatricula->id.'<br>';
                } catch (Exception $e) {
                	$msg .= 'Erro ao cadastrar a matrícula ID ' . $obMatricula->id . ': ' . $e->getMessage() . '<br>';
                }
            }
        } catch (Exception $e) {
        	$msg .= 'Erro na consulta principal: ' . $e->getMessage() . '<br>';
        }

        return $msg;
    }

    // Método para atualizar Certificados
    public static function atualizaCertificados() {
    	$msg = '';

    	$id_admin = 1;

    	// Aumenta o limite de execução para 0 (sem limite)
    	set_time_limit(0);

        // Inicializa a conexão com o banco de dados
    	self::initConnection();

    	try {
            // Consulta principal para obter todos os registros de 'carnes'
    		$query = self::$pdo->query("SELECT * FROM certificados");
    		$res = $query->fetchAll(PDO::FETCH_ASSOC);

    		foreach ($res as $certificado) {
    			$curso = $certificado['curso'];

                // Preparar a consulta para obter o id do pacote
    			$queryPacote = self::$pdo->prepare("SELECT id as id_pacote FROM pacotes WHERE nome = :nome_pacote");
    			$queryPacote->bindValue(":nome_pacote", $curso, PDO::PARAM_STR);
    			$queryPacote->execute();

                // Obter o id do pacote
    			$obPacote = $queryPacote->fetch(PDO::FETCH_ASSOC);

                // Verificar se a consulta retornou resultados
    			if (!$obPacote) {
    				$msg .= "Nenhum pacote encontrado para o nome: " . htmlspecialchars($curso) . "<br>";
                    continue; // Pula para a próxima iteração do loop
                }

                $id_pacote = $obPacote['id_pacote'];

                if (empty($id_pacote)) {
                	$msg .= "ID do pacote vazio para o pacote: " . htmlspecialchars($curso) . "<br>";
                    continue; // Pula para a próxima iteração do loop
                }
                
				//NOVA INSTANCIA
                $obData = new EntityCertificados;
                $obData->id_aluno = $certificado['id_aluno'];
                $obData->id_trilha = $id_pacote;
                $obData->carga_h = $certificado['horas'];
                $obData->modulos = $certificado['modulos'];
                $obData->conclusao = $certificado['conclusao'];
                $obData->codigo = $certificado['codigo'];
                $obData->id_admin = $id_admin;
                $obData->cadastrar();

                if($obData){
                	$msg .= 'certificado cadastrado com sucesso.<br>';
                } else {
                	$msg .= 'Erro ao cadastrar certificado<br>';
                }
                
            }

        } catch (Exception $e) {
        	$msg .= 'Erro na consulta principal: ' . $e->getMessage() . '<br>';
        }

        return $msg;
    }

    public static function atualizaAdmin(){

    	$msg='';

	    // Cadastro do responsável
    	$obUsers = new EntityUser;
    	$obUsers->id = 1;
    	$obUsers->id_x = 1;
    	$obUsers->nome = 'Danilo Rodrigues da Silva';
    	$obUsers->eh_responsavel = 0;
    	$obUsers->id_responsavel = 0;
    	$obUsers->email = 'contato@ctieducacional.com.br';
    	$obUsers->nivel = 'Diretor';
    	$obUsers->senha = password_hash('12345678', PASSWORD_DEFAULT);
    	$obUsers->whatsapp = '15998464457';
    	$obUsers->rg = '454338776';
    	$obUsers->cpf = '39512905876';
    	$obUsers->nascimento = '1987-10-08';
    	$obUsers->endereco = 'Rua Aparecida do Norte';
    	$obUsers->numero = 29;
    	$obUsers->bairro = 'C.D.H.U.';
    	$obUsers->uf = 26;
    	$obUsers->cidade = 8607;
    	$obUsers->ativo = 's';
    	$obUsers->termos_uso = 1;
    	$obUsers->acesso = '["home","Funcionários","Alunos","Responsáveis","Leads","Vouchers","Categorias","Trilhas","Certificados","Clientes","Matriculas","Entrada","Saída","Carnês","Recorrente","Relatórios","Parceiros","Contratos"]';
    	$obUsers->id_admin = 1;
    	$obUsers->cadastrar();

		// Mensagem de sucesso ou erro
    	if ($obUsers) {
    		$msg .= 'Admin ' . $obUsers->nome . ' cadastrado.<br>';
    	} else {
    		$msg .= 'Erro ao cadastrar admin.<br>';
    	}

    }


}
