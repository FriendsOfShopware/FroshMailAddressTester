<?php declare(strict_types=1);

namespace Frosh\MailAddressTester\Service;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shyim\CheckIfEmailExists\DNS;
use Shyim\CheckIfEmailExists\SMTP;
use Shyim\CheckIfEmailExists\Syntax;

class Tester
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $froshMailTesterLogger,
    ) {
    }

    public function validateEmail(string $email): bool
    {
        $email = \strtolower($email);
        $level = $this->systemConfigService->getString('FroshMailAddressTester.config.level');

        $mailValidCache = $this->getCacheItem($email, $level);
        $mailValidCacheResult = $mailValidCache->get();
        if (\is_bool($mailValidCacheResult)) {
            return $mailValidCacheResult;
        }

        $syntaxResult = new Syntax($email);
        if ($syntaxResult->isValid() === false) {
            return false;
        }

        $domain = $syntaxResult->domain;

        $domainValidCache = $this->getCacheItem($domain, $level);
        // first check if the domain is already marked as invalid
        if ($domainValidCache->get() === false) {
            return false;
        }

        $mxRecords = (new DNS())->getMxRecords($domain);

        if (empty($mxRecords)) {
            $this->saveCache($mailValidCache, false);
            $this->saveCache($domainValidCache, false);

            $this->froshMailTesterLogger->error(\sprintf('Domain %s has no mx records', $domain));

            return false;
        }

        if ($level !== 'smtp') {
            $this->saveCache($mailValidCache, true);

            return true;
        }

        $verifyEmail = $this->systemConfigService->getString('FroshMailAddressTester.config.verifyEmail');
        if ($verifyEmail !== '') {
            $smtpCheck = new SMTP($verifyEmail);
        } else {
            $smtpCheck = new SMTP();
        }

        $smtpResult = $smtpCheck->check($domain, $mxRecords, $email);
        $this->saveCache($domainValidCache, $smtpResult->canConnect);

        if ($smtpResult->canConnect === false) {
            $this->froshMailTesterLogger->error($smtpResult->error);

            return false;
        }

        $isValid = $smtpResult->isDeliverable === true && $smtpResult->isDisabled === false && $smtpResult->hasFullInbox === false;

        $this->saveCache($mailValidCache, $isValid);

        if ($isValid === false) {
            $this->froshMailTesterLogger->error(
                \sprintf('Email address "%s" test failed', $email),
                json_decode(json_encode($smtpResult, \JSON_THROW_ON_ERROR), true, 1, \JSON_THROW_ON_ERROR)
            );
        }

        return $isValid;
    }

    private function getCacheItem(string $value, string $level): CacheItemInterface
    {
        $cacheKey = 'frosh_mail_tester_' . $level . '_' . Hasher::hash($value);

        return $this->cache->getItem($cacheKey);
    }

    private function saveCache(CacheItemInterface $item, bool $value): void
    {
        $cacheTime = 3600;

        if ($value === true) {
            $cacheTime = 86400; // one day for valid emails
        }

        $item->expiresAfter($cacheTime);
        $item->set($value);
        $this->cache->save($item);
    }
}
