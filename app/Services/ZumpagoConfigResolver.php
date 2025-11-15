<?php

declare(strict_types=1);

namespace App\Services;

class ZumpagoConfigResolver
{
    /** @var array<string, mixed> */
    private array $sharedConfig = [];

    /** @var array<string, array<string, mixed>> */
    private array $profilesByCompanyId = [];

    /** @var array<string, array<string, mixed>> */
    private array $profilesByCommerceCode = [];

    /** @var array<string|int, array<string, mixed>> */
    private array $profiles = [];

    private ?string $defaultCompanyId = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->sharedConfig = $this->extractSharedConfig($config);
        $this->defaultCompanyId = $this->normalizeCompanyId($config['default_company_id'] ?? null);

        $companies = (array) ($config['companies'] ?? []);
        foreach ($companies as $id => $profileConfig) {
            if (!is_array($profileConfig)) {
                continue;
            }

            $profile = $this->buildProfile((string) $id, $profileConfig);
            $companyId = $profile['company_id'] ?? null;
            $commerceCode = $profile['company_code'] ?? null;

            if ($companyId !== null && $companyId !== '') {
                $this->profilesByCompanyId[$companyId] = $profile;
            }

            if ($commerceCode !== null && $commerceCode !== '') {
                $this->profilesByCommerceCode[$commerceCode] = $profile;
            }

            $this->storeProfile($profile);
        }

        if (empty($this->profiles)) {
            $this->profiles[] = $this->sharedConfig;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveByCompanyId(?string $companyId): array
    {
        $normalizedId = $this->normalizeCompanyId($companyId);
        if ($normalizedId !== '' && isset($this->profilesByCompanyId[$normalizedId])) {
            return $this->profilesByCompanyId[$normalizedId];
        }

        return $this->getDefaultProfile();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveByCommerceCode(?string $commerceCode): array
    {
        $normalizedCode = $this->normalizeCommerceCode($commerceCode);
        if ($normalizedCode !== '' && isset($this->profilesByCommerceCode[$normalizedCode])) {
            return $this->profilesByCommerceCode[$normalizedCode];
        }

        return $this->getDefaultProfile();
    }

    public function hasCompanyProfile(?string $companyId): bool
    {
        $normalizedId = $this->normalizeCompanyId($companyId);
        return $normalizedId !== '' && isset($this->profilesByCompanyId[$normalizedId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProfiles(): array
    {
        if (empty($this->profiles)) {
            return [$this->sharedConfig];
        }

        return array_values($this->profiles);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultProfile(): array
    {
        if ($this->defaultCompanyId !== null && isset($this->profilesByCompanyId[$this->defaultCompanyId])) {
            return $this->profilesByCompanyId[$this->defaultCompanyId];
        }

        if (!empty($this->profilesByCompanyId)) {
            $first = reset($this->profilesByCompanyId);
            if (is_array($first)) {
                return $first;
            }
        }

        if (!empty($this->profiles)) {
            $first = reset($this->profiles);
            if (is_array($first)) {
                return $first;
            }
        }

        return $this->sharedConfig;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function extractSharedConfig(array $config): array
    {
        unset($config['companies'], $config['default_company_id']);
        return $config;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function buildProfile(string $key, array $profile): array
    {
        $companyId = $this->normalizeCompanyId($profile['company_id'] ?? $profile['idempresa'] ?? $key);
        $commerceCode = $this->normalizeCommerceCode($profile['company_code'] ?? null);
        $label = trim((string) ($profile['label'] ?? ''));

        $profile['company_id'] = $companyId;
        $profile['idempresa'] = $companyId;
        $profile['company_code'] = $commerceCode;

        if ($label === '' && $companyId !== '') {
            $profile['label'] = $companyId;
        } else {
            $profile['label'] = $label;
        }

        return array_replace($this->sharedConfig, $profile);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function storeProfile(array $profile): void
    {
        $companyId = $profile['company_id'] ?? null;
        if (is_string($companyId) && $companyId !== '') {
            $this->profiles[$companyId] = $profile;
            return;
        }

        $this->profiles[] = $profile;
    }

    private function normalizeCompanyId(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $normalized = strtoupper(preg_replace('/[^0-9K]/i', '', $value) ?? '');

        return $normalized;
    }

    private function normalizeCommerceCode(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return strtoupper($value);
    }
}
