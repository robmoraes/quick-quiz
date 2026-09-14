<?php

namespace App\Tests\Repository;

use App\Repository\AdminRepository;
use App\Repository\AiPromptRepository;
use App\Repository\DatabaseConnectionFactory;
use App\Service\AiPromptDefaults;
use PHPUnit\Framework\TestCase;

final class PostgresRepositoryIntegrationTest extends TestCase
{
    public function testAdminAndPromptRepositoriesUsePostgres(): void
    {
        $databaseUrl = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($databaseUrl, 'postgres://') && !str_starts_with($databaseUrl, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }

        $suffix = bin2hex(random_bytes(6));
        $email = 'integration-'.$suffix.'@quickquiz.invalid';
        $theme = 'integration-'.$suffix;
        $prompts = new AiPromptRepository($databaseUrl);

        try {
            $admins = new AdminRepository($databaseUrl);
            $admins->createAdmin($email, 'integration-password');
            self::assertSame($email, $admins->findByEmail($email)['email'] ?? null);

            $key = AiPromptDefaults::ANSWER_RECOMMENDATION;
            $prompts->save($theme, $key, 'PostgreSQL integration prompt.');
            self::assertSame('PostgreSQL integration prompt.', $prompts->text($theme, $key));
        } finally {
            $prompts->restoreDefault($theme, AiPromptDefaults::ANSWER_RECOMMENDATION);
            $statement = DatabaseConnectionFactory::connect($databaseUrl)
                ->prepare('DELETE FROM admins WHERE email = :email');
            $statement->execute(['email' => $email]);
        }
    }
}
