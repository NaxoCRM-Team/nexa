<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Authentication\Helper;

use Espo\Core\Authentication\Logins\ApiKey;
use Espo\Core\Authentication\Logins\Hmac;
use Espo\ORM\EntityManager;
use Espo\Entities\User;

class UserFinder
{
    public function __construct(private EntityManager $entityManager)
    {}

    public function find(string $username): ?User
    {
        $identity = $this->identityWhere($username);

        return $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where($identity)
            ->where(['type!=' => [User::TYPE_API, User::TYPE_SYSTEM]])
            ->findOne();
    }

    public function findByIdAndHash(string $username, string $id, ?string $hash): ?User
    {
        $identity = $this->identityWhere($username);

        $where = [
            'id' => $id,
            'type!=' => [User::TYPE_API, User::TYPE_SYSTEM],
        ];

        if ($hash) {
            $where['password'] = $hash;
        }

        return $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where($identity)
            ->where($where)
            ->findOne();
    }

    /** @return array<string, mixed> */
    private function identityWhere(string $identifier): array
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return ['loginEmail' => strtolower($identifier)];
        }

        // Temporary compatibility for pre-migration administrators that do
        // not yet have a primary email. Email-backed users cannot use this.
        return [
            'userName' => $identifier,
            'loginEmail' => null,
        ];
    }

    public function findApiHmac(string $apiKey): ?User
    {
        return $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where([
                'type' => User::TYPE_API,
                'apiKey' => $apiKey,
                'authMethod' => Hmac::NAME,
            ])
            ->findOne();
    }

    public function findApiApiKey(string $apiKey): ?User
    {
        return $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where([
                'type' => User::TYPE_API,
                'apiKey' => $apiKey,
                'authMethod' => ApiKey::NAME,
            ])
            ->findOne();
    }
}
