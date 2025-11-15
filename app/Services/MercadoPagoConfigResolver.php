<?php

declare(strict_types=1);

namespace App\Services;

class MercadoPagoConfigResolver
{
    /** @var array<string, mixed> */
    private array $sharedConfig = [];

    /** @var array<string, array<string, mixed>> */
    private array $profilesByCompanyId = [];

    /** @var array<int|string, array<string, mixed>> */
    private array $profiles = [];

    private ?string $defaultCompanyId = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->defaultCompanyId = $this->normalizeCompanyId($config['default_company_id'] ?? null);
        $this->sharedConfig = $this->extractSharedConfig($config);

        $defaultProfile = $this->buildProfile($this->defaultCompanyId ?? 'default', [
            'company_id' => $this->defaultCompanyId,
        ]);
        $this->storeProfile($defaultProfile);

        $companies = (array) ($config['companies'] ?? []);
        foreach ($companies as $key => $companyConfig) {
            if (!is_array($companyConfig)) {
                continue;
            }

            $profile = $this->buildProfile((string) $key, $companyConfig);
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
        $normalized = $this->normalizeCompanyId($companyId);
        if ($normalized !== '' && isset($this->profilesByCompanyId[$normalized])) {
            return $this->profilesByCompanyId[$normalized];
        }

        return $this->getDefaultProfile();
    }

    public function hasCompanyProfile(?string $companyId): bool
    {
        $normalized = $this->normalizeCompanyId($companyId);
        return $normalized !== '' && isset($this->profilesByCompanyId[$normalized]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultProfile(): array
    {
        if ($this->defaultCompanyId !== null && isset($this->profilesByCompanyId[$this->defaultCompanyId])) {
            return $this->profilesByCompanyId[$this->defaultCompanyId];
        }

        $first = reset($this->profiles);
        if ($first !== false && is_array($first)) {
            return $first;
        }

        return $this->sharedConfig;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfile(string $key, array $profile): array
    {
        $merged = array_replace($this->sharedConfig, $profile);
        $companyId = $this->normalizeCompanyId($merged['company_id'] ?? $merged['idempresa'] ?? $key);
        $label = trim((string) ($merged['label'] ?? ''));

        $merged['company_id'] = $companyId;
        $merged['label'] = $label !== '' ? $label : ($companyId !== '' ? $companyId : 'MercadoPago');

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSharedConfig(array $config): array
    {
        unset($config['companies'], $config['default_company_id']);
        return $config;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function storeProfile(array $profile): void
    {
        $companyId = (string) ($profile['company_id'] ?? '');
        if ($companyId !== '') {
            $this->profilesByCompanyId[$companyId] = $profile;
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

        $normalized = preg_replace('/[^0-9K]/i', '', $value);

        return strtoupper($normalized ?? '');
    }
}
