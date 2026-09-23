<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_Fornecedores
{
    public $id;
    public $nome;
    public $cnpj;
    public $telefone;
    public $email;
    public $contato;
    public $status;
    public $id_admin;
    public $created_at;
    public $updated_at;

    /* ==========================
     * BUSCAS
     * ========================== */

    public static function getById(int $id, ?int $idAdmin = null)
    {
        $where = 'id = ' . (int)$id;
        if ($idAdmin !== null) {
            $where .= ' AND id_admin = ' . (int)$idAdmin;
        }
        return (new Database('stq_fornecedores'))
            ->select($where)
            ->fetchObject(self::class);
    }

    public static function getAtivos(?int $idAdmin = null)
    {
        $where = 'status = 1';
        if ($idAdmin !== null) {
            $where .= ' AND id_admin = ' . (int)$idAdmin;
        }
        return (new Database('stq_fornecedores'))
            ->select($where);
    }

    /* ==========================
     * CADASTRO
     * ========================== */

    public function cadastrar(): bool
    {
        $this->id = (new Database('stq_fornecedores'))->insert([
            'nome'       => $this->nome,
            'cnpj'       => $this->cnpj,
            'telefone'   => $this->telefone,
            'email'      => $this->email,
            'contato'    => $this->contato,
            'status'     => 1,
            'id_admin'   => (int)$this->id_admin,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /* ==========================
     * ATUALIZAÇÃO
     * ========================== */

    public function atualizar(): bool
    {
        return (new Database('stq_fornecedores'))->update(
            'id = ' . $this->id,
            [
                'nome'       => $this->nome,
                'cnpj'       => $this->cnpj,
                'telefone'   => $this->telefone,
                'email'      => $this->email,
                'contato'    => $this->contato,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        );
    }

    /* ==========================
     * STATUS
     * ========================== */

    public function inativar(): bool
    {
        return (new Database('stq_fornecedores'))->update(
            'id = ' . $this->id,
            ['status' => 0]
        );
    }

    public function ativar(): bool
    {
        return (new Database('stq_fornecedores'))->update(
            'id = ' . $this->id,
            ['status' => 1]
        );
    }
}
