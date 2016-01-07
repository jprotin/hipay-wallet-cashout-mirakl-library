<?php
/**
 * File Processor.php
 *
 * @category
 * @package
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */

namespace Hipay\MiraklConnector\Vendor;

use Hipay\MiraklConnector\Api\Hipay\Model\BankInfo;
use Hipay\MiraklConnector\Api\Hipay\Model\MerchantData;
use Hipay\MiraklConnector\Api\Hipay\Model\UserAccountBasic;
use Hipay\MiraklConnector\Api\Hipay\Model\UserAccountDetails;
use Hipay\MiraklConnector\Common\AbstractProcessor;
use Hipay\MiraklConnector\Service\Ftp;
use Hipay\MiraklConnector\Service\Ftp\ConfigurationInterface as FtpConfiguration;
use Hipay\MiraklConnector\Service\Zip;
use Hipay\MiraklConnector\Vendor\Event\AddBankAccountEvent;
use Hipay\MiraklConnector\Vendor\Event\CreateWalletEvent;
use Hipay\MiraklConnector\Api\Mirakl;
use Hipay\MiraklConnector\Api\Mirakl\ConfigurationInterface as MiraklConfiguration;
use Hipay\MiraklConnector\Api\Hipay;
use Hipay\MiraklConnector\Api\Hipay\ConfigurationInterface as HipayConfiguration;


/**
 * Class Processor
 *
 * @category
 * @package
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class Processor extends AbstractProcessor
{
    protected $ftp;

    /**
     * Processor constructor.
     * @param MiraklConfiguration $miraklConfig
     * @param HipayConfiguration $hipayConfig
     * @param FtpConfiguration $ftpConfiguration
     */
    public function __construct(
        MiraklConfiguration $miraklConfig,
        HipayConfiguration $hipayConfig,
        FtpConfiguration $ftpConfiguration
    )
    {
        parent::__construct($miraklConfig, $hipayConfig);
        $this->ftp = new Ftp(
            $ftpConfiguration->getHost(),
            $ftpConfiguration->getPort(),
            $ftpConfiguration->getConnectionType(),
            $ftpConfiguration->getUsername(),
            $ftpConfiguration->getPassword()
        );
    }

    /**
     * Fetch the vendors from Mirakl
     *
     * @param \DateTime $lastUpdate
     * @return array
     */
    public function getVendors(\DateTime $lastUpdate = null)
    {
        return $this->mirakl->getVendors($lastUpdate);
    }

    /**
     * Check if the vendor already has a wallet
     *
     * @param VendorInterface $vendor
     *
     * @return bool
     */
    public function hasWallet(VendorInterface $vendor)
    {
        $result = $this->hipay->isAvailable(
            $vendor->getEmail()
        );
        return !$result['isAvailable'];
    }

    /**
     * Create a Hipay wallet
     *
     * Dispatch the event <b>before.wallet.create</b> before sending the data to Hipay
     *
     * @param array $shopData
     * @param string $locale the locale in the format 'language_territory'
     * @param string $timeZone the timezone in the tz format
     * @return int the created account id|false if the creation failed
     */
    public function createWallet(
        array $shopData,
        $locale = 'fr_FR',
        $timeZone = 'Europe/Paris'
    )
    {
        $userAccountBasic = new UserAccountBasic(
            $shopData,
            $locale
        );
        $userAccountDetails = new UserAccountDetails(
            $shopData,
            $timeZone
        );
        $merchantData = new MerchantData($shopData);

        $event =  new CreateWalletEvent(
            $shopData,
            $userAccountBasic,
            $userAccountDetails,
            $merchantData
        );

        $this->dispatcher->dispatch(
            'before.wallet.create',
            $event
        );

        $result = $this->hipay->createFullUseraccount(
            $event->getUserAccountBasic(),
            $event->getUserAccountDetails(),
            $event->getMerchantData()
        );
        $result['userAccountId'];
    }

    /**
     * Transfer the files from Mirakl to Hipay using ftp
     *
     * @param array $shopIds
     * @param $tmpZipFilePath
     * @param $ftpShopsPath
     * @param null $tmpExtractPath
     */
    public function transferFiles(
        array $shopIds,
        $tmpZipFilePath,
        $ftpShopsPath,
        $tmpExtractPath = null)
    {
        //Downloads the zip file containg the documents
        file_put_contents(
            $tmpZipFilePath,
            $this->mirakl->downloadFiles($shopIds)
        );

        $zip = new Zip($tmpZipFilePath);

        $tmpExtractPath = $tmpExtractPath ?: dirname($tmpZipFilePath);

        if ($zip->extractFiles($tmpExtractPath)) {
            unlink($tmpZipFilePath);
        };

        $tmpExtractDirectory = opendir($tmpExtractPath);

        while (($shopDirectoryPath = readdir($tmpExtractDirectory)) !== false) {
            if (!is_dir($shopDirectoryPath)) {
                throw new \RuntimeException(
                    "$shopDirectoryPath should be a directory"
                );
            }
            $shopId = basename($shopDirectoryPath);
            $ftpShopDirectory = $ftpShopsPath . DIRECTORY_SEPARATOR . $shopId;
            $this->ftp->createDirectory($ftpShopDirectory);

            $shopDirectory = opendir($shopDirectoryPath);
            while (($shopDocument = readdir($shopDirectory)) !== false) {
                $source = $shopDirectoryPath . DIRECTORY_SEPARATOR . $shopDocument;
                $destination = $ftpShopDirectory . DIRECTORY_SEPARATOR . $shopDocument;
                if ($this->ftp->uploadFile($source, $destination) == false) {
                    throw new \RuntimeException(
                        "The uploading of the document $source has failed."
                    );
                };
            }
        }
    }

    /**
     * Get bank info status from Hipay
     *
     * @param VendorInterface $vendor
     * @param string $locale
     *
     * @return string
     */
    public function getBankInfoStatus(
        VendorInterface $vendor,
        $locale = 'fr_FR'
    )
    {
        $result = $this->hipay->bankInfosStatus($vendor, $locale);
        return $result['status'];
    }
    /**
     * Check that the bank information is the same in the two services
     *
     * @param VendorInterface $vendor
     * @param array $shopData
     *
     * @return bool
     */
    public function checkIban(
        array $shopData
    )
    {
        $bankInfo = new BankInfo($shopData);
        return $bankInfo->getIban() == $shopData['payment_info']['iban'];
    }

    /**
     * Add bank account information to Hipay
     * Dispatch the event <b>before.bankAccount.add</b>
     *
     * @param VendorInterface $vendor
     * @param array $shopData
     *
     * @return bool
     */
    public function addBankAccount(VendorInterface $vendor, array $shopData)
    {
        $bankInfo = new BankInfo($shopData);

        $event = new AddBankAccountEvent($shopData, $bankInfo);

        $this->dispatcher->dispatch(
            'before.bankAccount.add',
            $event
        );

        return $this->hipay->bankInfoRegister($vendor, $event->getBankInfo());
    }
}