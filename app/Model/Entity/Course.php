<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class Course {
    /**
     * Retorna todos os cursos profissionalizantes (trilhas)
     */
    public static function getProfessionalCourses() {
        $db = new Database('trilhas');
        return $db->select(
            "id_admin > 0",
            'nome ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Retorna todos os cursos de graduação
     */
    public static function getGraduationCourses() {
        $db = new Database('graduacoes');
        return $db->select(
            null,
            'curso ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
} 