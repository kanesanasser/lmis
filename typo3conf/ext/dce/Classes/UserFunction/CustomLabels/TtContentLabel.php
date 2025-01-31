<?php
namespace ArminVieweg\Dce\UserFunction\CustomLabels;

/*  | This extension is made for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2012-2018 Armin Vieweg <armin@v.ieweg.de>
 */
use ArminVieweg\Dce\Components\BackendView\SimpleBackendView;
use ArminVieweg\Dce\Utility\DatabaseUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Extends TCA label of fields with variable key
 */
class TtContentLabel
{
    /**
     * User function to get custom labels for tt_content.
     * This is required, when content elements based on DCE use
     * the Simple Backend View.
     *
     * @param array $parameter
     * @return void
     */
    public function getLabel(&$parameter)
    {
        if ((\is_string($parameter['row']['CType']) || \is_array($parameter['row']['CType'])) &&
            $this->isDceContentElement($parameter['row'])
        ) {
            try {
                /** @var \ArminVieweg\Dce\Domain\Model\Dce $dce */
                $dce = DatabaseUtility::getDceObjectForContentElement($parameter['row']['uid']);
            } catch (\Exception $exception) {
                $parameter['title'] = 'ERROR: ' . $exception->getMessage();
                return;
            }

            if ($dce->isUseSimpleBackendView()) {
                $objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
                /** @var SimpleBackendView $simpleBackendViewUtility */
                $simpleBackendViewUtility = $objectManager->get(SimpleBackendView::class);
                $headerContent = $simpleBackendViewUtility->getHeaderContent($dce, true);
                if (!empty($headerContent)) {
                    $parameter['title'] = $headerContent;
                    return;
                }
            } else {
                $parameter['title'] = trim(strip_tags($dce->renderBackendTemplate('header')));
                return;
            }
        }
        $parameter['title'] = $parameter['row'][$GLOBALS['TCA']['tt_content']['ctrl']['label']];
    }

    /**
     * Checks if given tt_content row is a content element based on DCE
     *
     * @param array $row
     * @return bool
     */
    protected function isDceContentElement(array $row)
    {
        $cType = $row['CType'];
        if (\is_array($cType)) {
            // For any reason the CType can be an array with one entry
            $cType = reset($cType);
        }
        return strpos($cType, 'dce_dceuid') !== false;
    }
}
