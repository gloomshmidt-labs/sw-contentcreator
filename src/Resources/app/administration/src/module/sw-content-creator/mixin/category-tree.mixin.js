/**
 * Kategorie-Auswahl wie im Textoptimierung-Tool: zuerst Verkaufskanal
 * (nur Storefront-Typ), dann ein Dropdown mit dem hierarchisch eingerückten
 * Kategoriebaum des Kanals (DFS ab navigationCategory, Geschwister in
 * Shopware-Reihenfolge).
 */
const STOREFRONT_TYPE_ID = '8a243080f92e4c719546314b577cf82b';
const MAX_TREE_SIZE = 500;

export default {
    data() {
        return {
            categorySalesChannelId: null,
            categoryRootId: null,
            categoryOptions: [],
            categoryTreeLoading: false,
        };
    },

    computed: {
        storefrontChannelCriteria() {
            const criteria = new Shopware.Data.Criteria(1, 25);
            criteria.addFilter(Shopware.Data.Criteria.equals('typeId', STOREFRONT_TYPE_ID));
            return criteria;
        },
    },

    methods: {
        onCategoryChannelChange(id) {
            this.categorySalesChannelId = id;
            this.categoryOptions = [];
            if (typeof this.resetCategorySelection === 'function') {
                this.resetCategorySelection();
            }
            if (!id) {
                this.categoryRootId = null;
                return;
            }
            this.repositoryFactory.create('sales_channel')
                .get(id, Shopware.Context.api)
                .then((salesChannel) => {
                    this.categoryRootId = salesChannel?.navigationCategoryId || null;
                    if (this.categoryRootId) {
                        this.loadCategoryTree();
                    }
                })
                .catch(() => { this.categoryRootId = null; });
        },

        loadCategoryTree() {
            this.categoryTreeLoading = true;
            const criteria = new Shopware.Data.Criteria(1, MAX_TREE_SIZE);
            criteria.addFilter(Shopware.Data.Criteria.multi('OR', [
                Shopware.Data.Criteria.contains('path', `|${this.categoryRootId}|`),
                Shopware.Data.Criteria.equals('id', this.categoryRootId),
            ]));

            this.repositoryFactory.create('category')
                .search(criteria, this.languageContext || Shopware.Context.api)
                .then((result) => {
                    const byParent = new Map();
                    result.forEach((category) => {
                        const key = category.parentId || 'root';
                        if (!byParent.has(key)) {
                            byParent.set(key, []);
                        }
                        byParent.get(key).push(category);
                    });
                    byParent.forEach((list) => list.sort((a, b) => (a.autoIncrement || 0) - (b.autoIncrement || 0)));

                    const options = [];
                    const walk = (parentId, depth) => {
                        (byParent.get(parentId) || []).forEach((category) => {
                            const name = category.translated?.name || category.name || category.id;
                            options.push({ value: category.id, label: `${'— '.repeat(depth)}${name}` });
                            walk(category.id, depth + 1);
                        });
                    };
                    const root = result.get(this.categoryRootId);
                    if (root) {
                        options.push({ value: root.id, label: root.translated?.name || root.name || root.id });
                        walk(root.id, 1);
                    }
                    this.categoryOptions = options;
                })
                .catch(() => { this.categoryOptions = []; })
                .finally(() => { this.categoryTreeLoading = false; });
        },
    },
};
