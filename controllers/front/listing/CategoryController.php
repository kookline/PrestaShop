<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
use PrestaShop\PrestaShop\Adapter\Category\CategoryProductSearchProvider;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;

class CategoryControllerCore extends ProductListingFrontController
{
    /** string Internal controller name */
    public $php_self = 'category';

    /** @var bool If set to false, customer cannot view the current category. */
    public $customer_access = true;

    /** @var bool */
    protected $notFound = false;

    /**
     * @var Category
     */
    protected $category;

    public function canonicalRedirection($canonicalURL = '')
    {
        if (Validate::isLoadedObject($this->category)) {
            parent::canonicalRedirection($this->context->link->getCategoryLink($this->category));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getCanonicalURL()
    {
        if (!Validate::isLoadedObject($this->category)) {
            return '';
        }
        $canonicalUrl = $this->context->link->getCategoryLink($this->category);
        $parsedUrl = parse_url($canonicalUrl);
        $params = [];
        $page = (int) Tools::getValue('page');

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $params);
        }

        if ($page > 1) {
            $params['page'] = $page;
        } else {
            unset($params['page']);
        }

        return http_build_url($parsedUrl, ['query' => http_build_query($params)]);
    }

    /**
     * Initializes controller.
     *
     * @see FrontController::init()
     *
     * @throws PrestaShopException
     */
    public function init()
    {
        $id_category = (int) Tools::getValue('id_category');
        $this->category = new Category(
            $id_category,
            $this->context->language->id
        );

        parent::init();

        if (!Validate::isLoadedObject($this->category) || !$this->category->active) {
            header('HTTP/1.1 404 Not Found');
            header('Status: 404 Not Found');
            $this->setTemplate('errors/404');
            $this->notFound = true;

            return;
        } elseif (!$this->category->checkAccess($this->context->customer->id)) {
            header('HTTP/1.1 403 Forbidden');
            header('Status: 403 Forbidden');
            $this->errors[] = $this->trans('You do not have access to this category.', [], 'Shop.Notifications.Error');
            $this->setTemplate('errors/forbidden');

            return;
        }

        $categoryVar = $this->getTemplateVarCategory();

        $filteredCategory = Hook::exec(
            'filterCategoryContent',
            ['object' => $categoryVar],
            $id_module = null,
            $array_return = false,
            $check_exceptions = true,
            $use_push = false,
            $id_shop = null,
            $chain = true
        );
        if (!empty($filteredCategory['object'])) {
            $categoryVar = $filteredCategory['object'];
        }

        $this->context->smarty->assign([
            'category' => $categoryVar,
            'subcategories' => $this->getTemplateVarSubCategories(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function initContent()
    {
        parent::initContent();

        if (
            Validate::isLoadedObject($this->category)
            && $this->category->active
            && $this->category->checkAccess($this->context->customer->id)
        ) {
            $this->doProductSearch(
                'catalog/listing/category',
                [
                    'entity' => 'category',
                    'id' => $this->category->id,
                ]
            );
        }
    }

    /**
     * overrides layout if category is not visible.
     *
     * @return bool|string
     */
    public function getLayout()
    {
        if (!$this->category->checkAccess($this->context->customer->id) || $this->notFound) {
            return 'layouts/layout-full-width.tpl';
        }

        return parent::getLayout();
    }

    protected function getAjaxProductSearchVariables()
    {
        $data = parent::getAjaxProductSearchVariables();
        $rendered_products_header = $this->render('catalog/_partials/category-header', ['listing' => $data]);
        $data['rendered_products_header'] = $rendered_products_header;

        return $data;
    }

    protected function getProductSearchQuery()
    {
        $query = new ProductSearchQuery();
        $query
            ->setIdCategory($this->category->id)
            ->setSortOrder(new SortOrder('product', Tools::getProductsOrder('by'), Tools::getProductsOrder('way')));

        return $query;
    }

    protected function getDefaultProductSearchProvider()
    {
        return new CategoryProductSearchProvider(
            $this->getTranslator(),
            $this->category
        );
    }

    protected function getTemplateVarCategory()
    {
        $category = $this->objectPresenter->present($this->category);
        $category['image'] = $this->getImage(
            $this->category,
            $this->category->id_image
        );

        return $category;
    }

    protected function getTemplateVarSubCategories()
    {
        return array_map(function (array $category) {
            $object = new Category(
                $category['id_category'],
                $this->context->language->id
            );

            $category['image'] = $this->getImage(
                $object,
                $object->id_image
            );

            $category['url'] = $this->context->link->getCategoryLink(
                $category['id_category'],
                $category['link_rewrite']
            );

            return $category;
        }, $this->category->getSubCategories($this->context->language->id));
    }

    protected function getImage($object, $id_image)
    {
        $retriever = new ImageRetriever(
            $this->context->link
        );

        return $retriever->getImage($object, $id_image);
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();

        foreach ($this->category->getAllParents() as $category) {
            if ($category->id_parent != 0 && !$category->is_root_category && $category->active) {
                $breadcrumb['links'][] = [
                    'title' => $category->name,
                    'url' => $this->context->link->getCategoryLink($category),
                ];
            }
        }

        if ($this->category->id_parent != 0 && !$this->category->is_root_category && $this->category->active) {
            $breadcrumb['links'][] = [
                'title' => $this->category->name,
                'url' => $this->context->link->getCategoryLink($this->category),
            ];
        }

        return $breadcrumb;
    }

    public function getCategory()
    {
        return $this->category;
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();

        if ($this->notFound) {
            $page['page_name'] = 'pagenotfound';
            $page['body_classes']['pagenotfound'] = true;
            $page['title'] = $this->trans('The page you are looking for was not found.', [], 'Shop.Theme.Global');
        } else {
            $page['body_classes']['category-id-' . $this->category->id] = true;
            $page['body_classes']['category-' . $this->category->name] = true;
            $page['body_classes']['category-id-parent-' . $this->category->id_parent] = true;
            $page['body_classes']['category-depth-level-' . $this->category->level_depth] = true;
        }

        return $page;
    }

    public function getListingLabel()
    {
        if (!Validate::isLoadedObject($this->category)) {
            $this->category = new Category(
                (int) Tools::getValue('id_category'),
                $this->context->language->id
            );
        }

        return $this->trans(
            'Category: %category_name%',
            ['%category_name%' => $this->category->name],
            'Shop.Theme.Catalog'
        );
    }
}
