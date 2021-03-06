<?php

namespace HiPay\Wallet\Mirakl\Vendor;

use DateTime;
use Exception;
use HiPay\Wallet\Mirakl\Api\Factory as ApiFactory;
use HiPay\Wallet\Mirakl\Api\HiPay;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Soap\BankInfo;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Soap\MerchantData;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Soap\UserAccountBasic;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Soap\UserAccountDetails;
use HiPay\Wallet\Mirakl\Api\HiPay\Model\Status\BankInfo as BankInfoStatus;
use HiPay\Wallet\Mirakl\Api\Mirakl;
use HiPay\Wallet\Mirakl\Common\AbstractApiProcessor;
use HiPay\Wallet\Mirakl\Exception\BankAccountCreationFailedException;
use HiPay\Wallet\Mirakl\Exception\DispatchableException;
use HiPay\Wallet\Mirakl\Exception\FTPUploadFailed;
use HiPay\Wallet\Mirakl\Exception\InvalidBankInfoException;
use HiPay\Wallet\Mirakl\Exception\InvalidVendorException;
use HiPay\Wallet\Mirakl\Service\Ftp;
use HiPay\Wallet\Mirakl\Service\Ftp\Factory as FTPFactory;
use HiPay\Wallet\Mirakl\Service\Validation\ModelValidator;
use HiPay\Wallet\Mirakl\Service\Zip;
use HiPay\Wallet\Mirakl\Vendor\Event\AddBankAccount;
use HiPay\Wallet\Mirakl\Vendor\Event\CheckAvailability;
use HiPay\Wallet\Mirakl\Vendor\Event\CheckBankInfos;
use HiPay\Wallet\Mirakl\Vendor\Event\CreateWallet;
use HiPay\Wallet\Mirakl\Vendor\Model\ManagerInterface;
use HiPay\Wallet\Mirakl\Vendor\Model\VendorInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Touki\FTP\FTPInterface;
use Touki\FTP\Model\Directory;
use Touki\FTP\Model\File;

/**
 * Vendor processor handling the wallet creation
 * and the bank info registration and verification.
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
class Processor extends AbstractApiProcessor
{
    /** @var  FtpInterface */
    protected $ftp;

    /** @var ManagerInterface */
    protected $vendorManager;

    /**
     * Processor constructor.
     *
     * @param EventDispatcherInterface $dispatcherInterface
     * @param LoggerInterface $logger
     * @param ApiFactory $factory
     * @param FTPFactory $ftpFactory
     * @param ManagerInterface $vendorManager
     */
    public function __construct(
        EventDispatcherInterface $dispatcherInterface,
        LoggerInterface $logger,
        ApiFactory $factory,
        FTPFactory $ftpFactory,
        ManagerInterface $vendorManager
    ) {
        parent::__construct(
            $dispatcherInterface,
            $logger,
            $factory
        );

        $this->ftp = $ftpFactory->getFTP();

        $this->vendorManager = $vendorManager;
    }

    /**
     * Main function to call who process the vendors
     * to create the wallets and register or verify the bank information.
     *
     * @param DateTime $lastUpdate
     * @param $zipPath
     * @param $ftpPath
     *
     * @codeCoverageIgnore
     */
    public function process($zipPath, $ftpPath, DateTime $lastUpdate = null)
    {
        try {
            $this->logger->info('Vendor Processing');

            //Vendor data fetching from Mirakl
            $this->logger->info('Vendors fetching from Mirakl');
            $miraklData = $this->getVendors($lastUpdate);
            $this->logger->info(
                '[OK] Fetched vendors from Mirakl : '.count($miraklData)
            );

            //Wallet creation
            $this->logger->info('Wallet creation');
            $vendorCollection = $this->registerWallets($miraklData);
            $this->logger->info('[OK] Wallets : ' . count($vendorCollection));

            //Vendor saving
            $this->logger->info("Saving vendor");
            $this->vendorManager->saveAll($vendorCollection);
            $this->logger->info("[OK] Vendor saved");

            //File transfer
            $this->logger->info('Transfer files');
            $errors = $this->transferFiles(
                array_keys($vendorCollection),
                $zipPath,
                $ftpPath
            );
            if ($errors) {
                $this->logger->error("There was some errors while transferring the files");
                return;
            }
            $this->logger->info('[OK] Files transferred');

            //Bank data updating
            $this->logger->info('Update bank data');
            $this->handleBankInfo($vendorCollection, $miraklData);
            $this->logger->info('[OK] Bank info updated');

        } catch (Exception $e) {
            $this->handleException($e, "critical");
        }
    }

    /**
     * Fetch the vendors from Mirakl.
     *
     * @param DateTime $lastUpdate
     *
     * @return array
     */
    public function getVendors(DateTime $lastUpdate = null)
    {
        $this->dispatcher->dispatch('before.vendor.get');
        $return = $this->mirakl->getVendors($lastUpdate);
        $this->dispatcher->dispatch('after.vendor.get');
        return $return;
    }

    /**
     * Register wallets into HiPay.
     *
     * @param $miraklData
     *
     * @return VendorInterface[] an array of vendor to save
     */
    public function registerWallets($miraklData)
    {
        $vendorCollection = array();

        foreach ($miraklData as $vendorData) {
            $this->logger->debug(
                'Shop id : {shopId}',
                array('shopId' => $vendorData['shop_id'])
            );

            try {
                //Vendor recording
                $email = $vendorData['contact_informations']['email'];
                $vendor = $this->vendorManager->findByEmail($email);
                if (!$vendor) {
                    if (!$this->hasWallet($email)) {
                        //Wallet create (call to HiPay)
                        $hipayId = $this->createWallet($vendorData);
                        $this->logger->info(
                            '[OK] Created wallet for : '.
                            $vendorData['shop_id'],
                            array('shopId' => $vendorData['shop_id'])
                        );
                    } else {
                        //Fetch the wallet id from HiPay
                        $hipayId = $this->hipay->getWalletId($email);
                    }
                    $vendor = $this->createVendor(
                        $email,
                        $hipayId,
                        $vendorData['shop_id'],
                        $vendorData
                    );
                }

                $previousValues = $this->getImmutableValues($vendor);
                //Put more data into the vendor
                $this->vendorManager->update($vendor, $vendorData);

                if (!$this->vendorManager->isValid($vendor)) {
                    throw new InvalidVendorException($vendor);
                };

                ModelValidator::validate($vendor);

                ModelValidator::checkImmutability($vendor, $previousValues);

                $vendorCollection[$vendor->getMiraklId()] = $vendor;
                $this->logger->info('[OK] The vendor is treated');
            } catch (DispatchableException $e) {
                $this->handleException($e, 'warning', array('shopId' => $vendorData['shop_id']));
            }
        }

        return $vendorCollection;
    }

    /**
     * Check if the vendor already has a wallet.
     *
     * Dispatch the event <b>before.availability.check</b>
     * before sending the data to HiPay
     *
     * @param string $email
     *
     * @return bool
     */
    public function hasWallet($email)
    {
        $event = new CheckAvailability($email);
        $this->dispatcher->dispatch('before.availability.check', $event);
        $result = $this->hipay->isAvailable($email, $event->getEntity());
        $this->dispatcher->dispatch('after.availability.check', $event);
        return !$result;
    }

    /**
     * Create a HiPay wallet.
     *
     * Dispatch the event <b>before.wallet.create</b>
     * before sending the data to HiPay
     *
     * @param array $shopData
     *
     * @return int the created account id
     */
    protected function createWallet(array $shopData)
    {
        $userAccountBasic = new UserAccountBasic($shopData);
        $userAccountDetails = new UserAccountDetails($shopData);
        $merchantData = new MerchantData($shopData);

        $event = new CreateWallet(
            $userAccountBasic,
            $userAccountDetails,
            $merchantData
        );

        $this->dispatcher->dispatch(
            'before.wallet.create',
            $event
        );

        $walletId = $this->hipay->createFullUseraccount(
            $event->getUserAccountBasic(),
            $event->getUserAccountDetails(),
            $event->getMerchantData()
        );

        $this->dispatcher->dispatch(
            'after.wallet.create',
            $event
        );

        return $walletId;
    }

    /**
     * To record a wallet in the database in the case there was an error.
     *
     * @param string $email
     * @param int $walletId
     * @param int $miraklId
     * @param array $miraklData
     * @return VendorInterface
     */
    protected function createVendor($email, $walletId, $miraklId, $miraklData)
    {
        $this->logger->debug("The wallet number is $walletId");
        $vendor = $this->vendorManager->create(
            $email,
            $miraklId,
            $walletId,
            $miraklData
        );
        $vendor->setEmail($email);
        $vendor->setHiPayId($walletId);
        $vendor->setMiraklId($miraklId);
        $this->logger->info('[OK] Wallet recorded');

        return $vendor;
    }

    /**
     * Return the values who should't
     * change after the registration of the hipay wallet.
     *
     * @param VendorInterface $vendor
     */
    protected function getImmutableValues(VendorInterface $vendor)
    {
        $previousValues['email'] = $vendor->getEmail();
        $previousValues['hipayId'] = $vendor->getHiPayId();
        $previousValues['miraklId'] = $vendor->getMiraklId();

        return $previousValues;
    }

    /**
     * Transfer the files from Mirakl to HiPay using ftp.
     *
     * @param array $shopIds
     * @param $tmpZipFilePath
     * @param $ftpShopsPath
     * @param null $tmpExtractPath
     * @return bool
     */
    public function transferFiles(
        array $shopIds,
        $tmpZipFilePath,
        $ftpShopsPath,
        $tmpExtractPath = null
    ) {
        $errors = false;
        //Check the zip path
        if (is_dir($tmpZipFilePath)) {
            throw new RuntimeException("The given path $tmpZipFilePath is a directory");
        }

        //Downloads the zip file containing the documents
        try {
            file_put_contents(
                $tmpZipFilePath,
                $this->mirakl->downloadShopsDocuments($shopIds)
            );
        } catch (Exception $e) {
            $this->logger->notice('No file was transferred');
            return true;
        }

        $zip = new Zip($tmpZipFilePath);

        $tmpExtractPath = $tmpExtractPath ?: rtrim(dirname($tmpZipFilePath), '/') .  DIRECTORY_SEPARATOR . 'hipay';

        if ($zip->extractFiles($tmpExtractPath)) {
            unlink($tmpZipFilePath);
        };

        $treatedShopIds = array();
        $tmpExtractDirectory = opendir($tmpExtractPath);

        while (($shopId = readdir($tmpExtractDirectory)) !== false) {
            //Ignore . and .. entries
            if ($shopId == '.'
                || $shopId == '..') {
                continue;
            }

            if (!in_array($shopId, $shopIds)) {
                $this->logger->notice("$shopId is ignored (not in the passed array)");
                continue;
            }

            $vendor = $this->vendorManager->findByMiraklId($shopId);

            if (!$vendor) {
                $this->logger->notice("The $shopId was not found in storage");
                continue;
            }

            $shopDirectoryPath = rtrim($tmpExtractPath, '/') .
                DIRECTORY_SEPARATOR . $shopId;

            //Check if $shopDirectoryPath is a directory
            if (!is_dir($shopDirectoryPath)) {
                throw new RuntimeException(
                    "$shopDirectoryPath should be a directory"
                );
            }

            //Construct the path for the ftp
            $ftpShopDirectoryPath = rtrim($ftpShopsPath, '/').
                DIRECTORY_SEPARATOR . $vendor->getHiPayId();

            //Check directory existence
            $ftpShopDirectory = new Directory($ftpShopDirectoryPath);
            if (!$this->ftp->directoryExists($ftpShopDirectory)) {
                //Create the ftp directory for the shop
                $this->ftp->create($ftpShopDirectory);
            };

            $shopDirectory = opendir($shopDirectoryPath);
            while (($shopDocument = readdir($shopDirectory)) !== false) {
                if ($shopDocument == '.' | $shopDocument == '..') {
                    continue;
                }
                $source = $shopDirectoryPath.
                    DIRECTORY_SEPARATOR .$shopDocument;
                $destination = $ftpShopDirectoryPath.
                    DIRECTORY_SEPARATOR .$shopDocument;
                $this->logger->info("Transfering $source");
                $file = new File($destination);
                //Upload the files
                if ($this->ftp->upload($file, $source) == false) {
                    $errors = true;
                    $this->handleException(new FTPUploadFailed($source, $destination));
                };
            }
            $treatedShopIds[] = $shopId;
        }

        $untreatedShop = array_diff($shopIds, $treatedShopIds);

        foreach ($untreatedShop as $shopId) {
            $this->logger->notice("$shopId had no document to transfer");
        }

        return $errors;
    }

    /**
     * Register the bank account and verify the
     * synchronicity of the bank information in both platform.
     *
     * @param VendorInterface[] $vendorCollection
     * @param array[]             $miraklDataCollection mirakl data
     * Expect one mirakl data for each vendor present in the vendorCollection
     */
    public function handleBankInfo($vendorCollection, $miraklDataCollection)
    {
        //Index mirakl Data
        $miraklDataCollection = $this->indexMiraklData($miraklDataCollection);

        /** @var VendorInterface $vendor */
        foreach ($vendorCollection as $vendor) {
            $this->logger->debug(
                'Shop id : '.$vendor->getMiraklId(),
                array('shopId' => $vendor->getMiraklId())
            );

            try {
                //Check if there is data associated to the current vendor
                if (!isset($miraklDataCollection[$vendor->getMiraklId()])) {
                    $this->logger->notice("The vendor {$vendor->getMiraklId()} in the mirakl collection");
                } else {
                    $bankInfoStatus = $this->getBankInfoStatus($vendor);

                    $miraklBankInfo = new BankInfo();
                    $miraklBankInfo->setMiraklData(
                        $miraklDataCollection[$vendor->getMiraklId()]
                    );

                    $this->logger->debug($bankInfoStatus);
                    switch (trim($bankInfoStatus)) {
                        case BankInfoStatus::BLANK:
                            if ($this->sendBankAccount($vendor, $miraklBankInfo)) {
                                $this->logger->info(
                                    '[OK] Created bank account for : ' .
                                    $vendor->getMiraklId(),
                                    array('shopId' => $vendor->getMiraklId())
                                );
                            } else {
                                throw new BankAccountCreationFailedException(
                                    $vendor,
                                    $miraklBankInfo
                                );
                            }
                            break;
                        case BankInfoStatus::VALIDATED:
                            if (!$this->isBankInfosSynchronised($vendor, $miraklBankInfo)) {
                                throw new InvalidBankInfoException(
                                    $vendor,
                                    $miraklBankInfo
                                );
                            } else {
                                $this->logger->info(
                                    '[OK] The bank information is synchronized'
                                );
                            }
                            break;
                        default:
                    }
                }
            } catch (InvalidBankInfoException $e) {
                $this->handleException($e, 'critical', array('shopId' => $vendor->getMiraklId()));
            }
            catch (Exception $e) {
                $this->handleException($e, 'warning', array('shopId' => $vendor->getMiraklId()));
            }
        }
    }

    /**
     * Index mirakl data fetched with a call to S20 resource from their API
     *
     * @param $miraklData
     * @return array
     */
    protected function indexMiraklData($miraklData)
    {
        $indexedMiraklData = array();
        foreach ($miraklData as $data) {
            $indexedMiraklData[$data['shop_id']] = $data;
        }
        return $indexedMiraklData;
    }

    /**
     * Get bank info status from HiPay.
     *
     * @param VendorInterface $vendor
     *
     * @return string
     */
    public function getBankInfoStatus(
        VendorInterface $vendor
    ) {
        $result = $this->hipay->bankInfosStatus($vendor);
        return $result;
    }

    /**
     * Add bank account information to HiPay
     * Dispatch the event <b>before.bankAccount.add</b>.
     *
     * @param VendorInterface $vendor
     * @param BankInfo        $bankInfo
     *
     * @return bool
     */
    protected function sendBankAccount(VendorInterface $vendor, BankInfo $bankInfo)
    {
        $event = new AddBankAccount($bankInfo);

        $this->dispatcher->dispatch(
            'before.bankAccount.add',
            $event
        );

        return $this->hipay->bankInfosRegister($vendor, $event->getBankInfo());
    }

    /**
     * Check that the bank information is the same in the two services.
     *
     * @param VendorInterface $vendor
     * @param BankInfo        $miraklBankInfo
     *
     * @return bool
     */
    protected function isBankInfosSynchronised(
        VendorInterface $vendor,
        BankInfo $miraklBankInfo
    ) {
        $hipayBankInfo = $this->getBankInfo($vendor);
        $event = new CheckBankInfos($miraklBankInfo, $hipayBankInfo);
        $ibanCheck = ($hipayBankInfo->getIban() == $miraklBankInfo->getIban());
        $this->dispatcher->dispatch('check.bankInfos.synchronicity', $event);
        return $ibanCheck && $event->isSynchrony();
    }

    /**
     * Return the bank info from HiPay.
     *
     * @param VendorInterface $vendor
     *
     * @return BankInfo
     */
    public function getBankInfo(VendorInterface $vendor)
    {
        return $this->hipay->bankInfosCheck($vendor);
    }

    /**
     * Return true if banking information is the same at Mirakl and HiPay
     * The soap call will fail if the bank info status at HiPay is not validated
     * @param VendorInterface $vendor
     * @param array|BankInfo $miraklBankInfo
     * @param boolean $checkBankStatus set to true if you also want to check to bank info status prior to fetch them
     * @return false|true is the status of the baking information is not validated
     * @throws InvalidBankInfoException the the information is not same by
     */
    public function isBankInfoUsable(VendorInterface $vendor, $miraklBankInfo, $checkBankStatus = false)
    {
        if ($checkBankStatus) {
            $bankInfoStatus = $this->getBankInfoStatus($vendor);

            if (trim($bankInfoStatus) == BankInfoStatus::VALIDATED) {
                return false;
            }
        }

        if (is_array($miraklBankInfo)) {
            $bankInfo = new BankInfo();
            $miraklBankInfo = $bankInfo->setMiraklData($miraklBankInfo);
        }

        return $this->isBankInfosSynchronised($vendor, $miraklBankInfo);
    }

    /**
     * Add the bank information to a wallet
     * The call will fail if the bank information status is not blank
     * @param VendorInterface $vendor
     * @param array|BankInfo $miraklBankInfo
     * @param bool|false $checkBankStatus set to true if you also want to check to bank info status prior to add them
     * @return bool
     */
    public function addBankInformation($vendor, $miraklBankInfo, $checkBankStatus = false)
    {
        if ($checkBankStatus) {
            $bankInfoStatus = $this->getBankInfoStatus($vendor);

            if (trim($bankInfoStatus) == BankInfoStatus::BLANK) {
                return false;
            }
        }

        if (is_array($miraklBankInfo)) {
            $bankInfo = new BankInfo();
            $miraklBankInfo = $bankInfo->setMiraklData($miraklBankInfo);
        }

        return $this->sendBankAccount($vendor, $miraklBankInfo);
    }

    /**
     * Save a vendor in case there was an error.
     *
     * @param string $email
     * @param int $miraklId
     */
    public function recordVendor($email, $miraklId)
    {
        $miraklData = current(
            $this->mirakl->getVendors(null, false, array($miraklId))
        );
        $hipayId = $this->hipay->getWalletId($miraklData['contact_informations']['email']);
        $vendor = $this->createVendor($email, $hipayId, $miraklId, $miraklData);
        $this->vendorManager->save($vendor);
    }

    /**
     * Returns the wallet registered at HiPay
     *
     * @param int $merchantGroupId
     * @param DateTime|null $pastDate
     *
     * @return array
     */
    public function getWallets($merchantGroupId, DateTime $pastDate = null)
    {
        if (!$pastDate) {
            $pastDate = new DateTime('1970-01-01');
        }
        return $this->hipay->getMerchantGroupAccounts($merchantGroupId, $pastDate);
    }
}
