<?php
namespace ArminVieweg\Dce\Controller;

/*  | This extension is made for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2012-2018 Armin Vieweg <armin@v.ieweg.de>
 */
use ArminVieweg\Dce\Components\DceContainer\ContainerFactory;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DCE Controller
 * Handles the output of content element based on DCEs in front- and backend.
 */
class DceController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * DCE Repository
     *
     * @var \ArminVieweg\Dce\Domain\Repository\DceRepository
     * @inject
     */
    protected $dceRepository;

    /**
     * TypoScript Utility
     *
     * @var \ArminVieweg\Dce\Utility\TypoScript
     * @inject
     */
    protected $typoScriptUtility;

    /**
     * @var array
     */
    public $temporaryDceProperties = [];

    /**
     * Initialize Action
     *
     * @return void
     */
    public function initializeAction()
    {
        if ($this->settings === null) {
            $this->settings = [];
        }
        $this->settings = $this->typoScriptUtility->renderConfigurationArray($this->settings);
    }

    /**
     * Show Action which get called if a DCE get rendered in frontend
     *
     * @return string output of dce in frontend
     */
    public function showAction()
    {
        $contentObject = $this->configurationManager->getContentObject()->data;
        $config = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK
        );

        /** @var $dce \ArminVieweg\Dce\Domain\Model\Dce */
        $dce = $this->dceRepository->findAndBuildOneByUid(
            \ArminVieweg\Dce\Domain\Repository\DceRepository::extractUidFromCtype($config['pluginName']),
            $this->settings,
            $contentObject
        );

        if ($dce->getEnableDetailpage()) {
            $detailUid = (int) GeneralUtility::_GP($dce->getDetailpageIdentifier());
            if ($detailUid) {
                // populate all elements to skip
                ContainerFactory::makeContainer($dce);
                if ((int) $contentObject['uid'] === $detailUid) {
                    return $dce->renderDetailpage();
                }
                return '<!--render detail-->'; //output needed for content slide
            }
        }
        if ($dce->getEnableContainer()) {
            if (ContainerFactory::checkContentElementForBeingRendered($dce->getContentObject())) {
                return '';
            }
            $container = ContainerFactory::makeContainer($dce);
            return $container->render();
        }
        ContainerFactory::clearContentElementsToSkip();

        return $dce->render();
    }

    /**
     * Render preview action
     *
     * @return string
     */
    public function renderPreviewAction() : string
    {
        $uid = (int) $this->settings['dceUid'];
        $contentObject = $this->getContentObject($this->settings['contentElementUid']);
        $previewType = $this->settings['previewType'];

        $this->settings = $this->simulateContentElementSettings($this->settings['contentElementUid']);

        /** @var $dce \ArminVieweg\Dce\Domain\Model\Dce */
        $dce = clone $this->dceRepository->findAndBuildOneByUid(
            $uid,
            $this->settings,
            $contentObject,
            true
        );

        if ($previewType === 'header') {
            return $dce->renderHeaderPreview();
        }
        return $dce->renderBodytextPreview();
    }

    /**
     * Renders DCE with given values.
     * If values are null, the values are read from $this->settings array.
     *
     * @param int|null $uid Uid of DCE
     * @param int|null $contentElementUid Uid of content element (tt_content)
     * @return string Serialized, (gz)compressed DCE model
     */
    public function renderDceAction($uid = null, $contentElementUid = null) : string
    {
        $uid = $uid ?? (int) $this->settings['dceUid'];
        $contentElementUid = $contentElementUid ?? $this->settings['contentElementUid'];
        $contentObject = $this->getContentObject($contentElementUid);

        $this->settings = $this->simulateContentElementSettings($this->settings['contentElementUid']);
        $dce = $this->dceRepository->findAndBuildOneByUid(
            $uid,
            $this->settings,
            $contentObject
        );
        return gzcompress(serialize($dce));
    }

    /**
     * Simulates content element settings, which is necessary in backend context
     *
     * @param int $contentElementUid
     * @return array
     */
    protected function simulateContentElementSettings($contentElementUid) : array
    {
        $row = \ArminVieweg\Dce\Utility\DatabaseUtility::getDatabaseConnection()->exec_SELECTgetSingleRow(
            'pi_flexform',
            'tt_content',
            'uid = ' . (int) $contentElementUid
        );

        $flexFormService = $this->objectManager->get('TYPO3\CMS\Extbase\Service\FlexFormService');
        $flexData = $flexFormService->convertFlexFormContentToArray($row['pi_flexform'], 'lDEF', 'vDEF');
        return $flexData['settings'];
    }

    /**
     * Returns an array with properties of content element with given uid
     *
     * @param int $uid of content element to get
     * @return array|bool|null with all properties of given content element uid
     */
    protected function getContentObject($uid)
    {
        return \ArminVieweg\Dce\Utility\DatabaseUtility::getDatabaseConnection()->exec_SELECTgetSingleRow(
            '*',
            'tt_content',
            'uid = ' . (int) $uid
        );
    }
}
