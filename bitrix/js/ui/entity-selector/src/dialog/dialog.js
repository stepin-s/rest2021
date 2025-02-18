import { Type, Text, Tag, Dom, ajax as Ajax, Cache, Loc, Runtime, Reflection } from 'main.core';
import { EventEmitter, BaseEvent } from 'main.core.events';
import { Popup } from 'main.popup';
import { Loader } from 'main.loader';

import Item from '../item/item';
import Tab from './tabs/tab';
import Entity from '../entity/entity';
import TagSelector from '../tag-selector/tag-selector';
import Navigation from './navigation';
import SliderIntegration from './integration/slider-integration';
import Animation from '../util/animation';
import BaseFooter from './footer/base-footer';
import DefaultFooter from './footer/default-footer';

import RecentTab from './tabs/recent-tab';
import SearchTab from './tabs/search-tab';

import type ItemNode from '../item/item-node';
import type { TabOptions } from './tabs/tab-options';
import type { DialogOptions } from './dialog-options';
import type { ItemOptions } from '../item/item-options';
import type { EntityOptions } from '../entity/entity-options';
import type { ItemId } from '../item/item-id';
import type { PopupOptions } from 'main.popup';

class LoadState
{
	static UNSENT: string = 'UNSENT';
	static LOADING: string = 'LOADING';
	static DONE: string = 'DONE';
}

class TagSelectorMode
{
	static INSIDE: string = 'INSIDE';
	static OUTSIDE: string = 'OUTSIDE';
}

const instances = new Map();

/**
 * @memberof BX.UI.EntitySelector
 */
export default class Dialog extends EventEmitter
{
	id: string = null;
	items: Map<string, Map<string, Item>> = new Map();
	tabs: Tab[] = [];
	entities: Entity[] = [];
	targetNode: HTMLElement = null;
	popup: Popup = null;
	cache = new Cache.MemoryCache();
	multiple: boolean = true;
	hideOnSelect: boolean = null;
	hideOnDeselect: boolean = null;
	context: string = null;
	selectedItems: Set<Item> = new Set();
	preselectedItems: ItemId[] = [];
	undeselectedItems: ItemId[] = [];
	dropdownMode: boolean = false;

	frozen: boolean = false;
	frozenProps: { [propName: string]: any } = {};

	hideByEsc: boolean = true;
	autoHide: boolean = true;
	offsetTop: number = 5;
	offsetLeft: number = 0;
	zIndex: number = null;
	cacheable: boolean = true;

	width: number = 565;
	height: number = 420;

	maxLabelWidth: number = 160;
	minLabelWidth: number = 40;

	activeTab: Tab = null;
	recentTab: Tab = null;
	searchTab: Tab = null;

	rendered: boolean = false;

	loadState: LoadState = LoadState.UNSENT;
	loader: ?Loader = null;

	tagSelector: ?TagSelector = null;
	tagSelectorMode: ?TagSelectorMode = null;
	tagSelectorHeight: ?number = null;

	saveRecentItemsWithDebounce: Function = Runtime.debounce(this.saveRecentItems, 2000, this);
	recentItemsToSave = [];

	navigation: Navigation = null;
	footer: BaseFooter = null;
	popupOptions: PopupOptions = {};

	focusOnFirst: boolean = true;
	focusedNode: ItemNode = null;

	static getById(id: string): ?Dialog
	{
		return instances.get(id) || null;
	}

	constructor(dialogOptions: DialogOptions)
	{
		super();
		this.setEventNamespace('BX.UI.EntitySelector.Dialog');

		const options = Type.isPlainObject(dialogOptions) ? dialogOptions : {};
		this.id = Type.isStringFilled(options.id) ? options.id : `ui-selector-${Text.getRandom().toLowerCase()}`;
		this.multiple = Type.isBoolean(options.multiple) ? options.multiple : true;
		this.context = Type.isStringFilled(options.context) ? options.context : null;

		if (Type.isArray(options.entities))
		{
			options.entities.forEach((entity) => {
				this.addEntity(entity);
			});
		}

		if (options.enableSearch === true)
		{
			const defaultOptions = {
				placeholder: Loc.getMessage('UI_TAG_SELECTOR_SEARCH_PLACEHOLDER'),
				maxHeight: 99,
				textBoxWidth: 105
			};
			const customOptions = Type.isPlainObject(options.tagSelectorOptions) ? options.tagSelectorOptions : {};
			const mandatoryOptions = {
				dialogOptions: null,
				showTextBox: true,
				showAddButton: false,
				showCreateButton: false,
				multiple: this.isMultiple()
			};

			const tagSelectorOptions = Object.assign(defaultOptions, customOptions, mandatoryOptions);
			const tagSelector = new TagSelector(tagSelectorOptions);
			this.tagSelectorMode = TagSelectorMode.INSIDE;
			this.setTagSelector(tagSelector);
		}
		else if (options.tagSelector instanceof TagSelector)
		{
			this.tagSelectorMode = TagSelectorMode.OUTSIDE;
			this.setTagSelector(options.tagSelector);
		}

		this.setTargetNode(options.targetNode);
		this.setHideOnSelect(options.hideOnSelect);
		this.setHideOnDeselect(options.hideOnDeselect);
		this.setWidth(options.width);
		this.setHeight(options.height);
		this.setAutoHide(options.autoHide);
		this.setHideByEsc(options.hideByEsc);
		this.setOffsetLeft(options.offsetLeft);
		this.setOffsetTop(options.offsetTop);
		this.setZindex(options.zIndex);
		this.setCacheable(options.cacheable);
		this.setFocusOnFirst(options.focusOnFirst);

		this.recentTab = new RecentTab(options.recentTabOptions);
		this.searchTab = new SearchTab(options.searchTabOptions, options.searchOptions);

		this.addTab(this.recentTab);
		this.addTab(this.searchTab);

		this.setDropdownMode(options.dropdownMode);
		this.setPreselectedItems(options.preselectedItems);
		this.setUndeselectedItems(options.undeselectedItems);

		this.setOptions(options);

		const preload = options.preload === true || this.getPreselectedItems().length > 0;
		if (preload)
		{
			this.load();
		}

		if (Type.isPlainObject(options.popupOptions))
		{
			const allowedOptions = ['overlay', 'bindOptions'];
			const popupOptions = {};

			Object.keys(options.popupOptions).forEach((option: string) => {
				if (allowedOptions.includes(option))
				{
					popupOptions[option] = options.popupOptions[option];
				}
			});

			this.popupOptions = popupOptions;
		}

		this.navigation = new Navigation(this);

		(new SliderIntegration(this));

		this.subscribe('ItemNode:onFocus', this.handleItemNodeFocus.bind(this));
		this.subscribe('ItemNode:onUnfocus', this.handleItemNodeUnfocus.bind(this));

		this.subscribeFromOptions(options.events);

		instances.set(this.id, this);
	}

	setOptions(dialogOptions: DialogOptions): void
	{
		const options = Type.isPlainObject(dialogOptions) ? dialogOptions : {};

		if (Type.isArray(options.tabs))
		{
			options.tabs.forEach((tab) => {
				this.addTab(tab);
			});
		}

		if (Type.isArray(options.selectedItems))
		{
			options.selectedItems.forEach((itemOptions: ItemOptions) => {
				const options = Object.assign({}, Type.isPlainObject(itemOptions) ? itemOptions : {});
				options.selected = true;
				this.addItem(options);
			});
		}

		if (Type.isArray(options.items))
		{
			options.items.forEach((itemOptions: ItemOptions) => {
				this.addItem(itemOptions);
			});
		}

		this.setFooter(options.footer, options.footerOptions);
	}

	getId(): string
	{
		return this.id;
	}

	getContext(): ?string
	{
		return this.context;
	}

	getNavigation(): Navigation
	{
		return this.navigation;
	}

	getFooter(): ?BaseFooter
	{
		return this.footer;
	}

	setFooter(footer: string | HTMLElement | HTMLElement[] | null, footerOptions?: { [option: string]: any })
	{
		if (!Type.isStringFilled(footer) && !Type.isArrayFilled(footer) && !Type.isDomNode(footer) && footer !== null)
		{
			return;
		}

		let instance = null;
		const options = Type.isPlainObject(footerOptions) ? footerOptions : {};

		if (Type.isString(footer))
		{
			const className = Reflection.getClass(footer);
			if (Type.isFunction(className))
			{
				instance = new className(this, options);
				if (!(instance instanceof BaseFooter))
				{
					console.error('EntitySelector: footer is not an instance of BaseFooter.');
					instance = null;
				}
			}
		}

		if (footer !== null && !instance)
		{
			instance = new DefaultFooter(this, Object.assign({}, options, { content: footer }));
		}

		this.footer = instance;

		if (this.isRendered())
		{
			Dom.clean(this.getFooterContainer());
			if (this.footer)
			{
				Dom.append(this.footer.render(), this.getFooterContainer());
			}
		}
	}

	addTab(tab: Tab | TabOptions): Tab
	{
		if (Type.isPlainObject(tab))
		{
			tab = new Tab(tab);
		}

		if (!(tab instanceof Tab))
		{
			throw new Error('EntitySelector: a tab must be an instance of EntitySelector.Tab.');
		}

		if (this.getTab(tab.getId()))
		{
			console.error(`EntitySelector: the "${tab.getId()}" tab is already existed.`);
			return tab;
		}

		tab.setDialog(this);
		this.tabs.push(tab);

		if (this.isRendered())
		{
			this.insertTab(tab);
		}

		return tab;
	}

	getTabs(): Tab[]
	{
		return this.tabs;
	}

	getTab(id: string): ?Tab
	{
		if (!Type.isStringFilled(id))
		{
			return null;
		}

		const tab = this.getTabs().find((tab: Tab) => tab.getId() === id);

		return tab || null;
	}

	getRecentTab(): RecentTab
	{
		return this.recentTab;
	}

	getSearchTab(): SearchTab
	{
		return this.searchTab;
	}

	selectTab(id: string): ?Tab
	{
		const newActiveTab = this.getTab(id);
		if (!newActiveTab || newActiveTab === this.getActiveTab())
		{
			return newActiveTab;
		}

		if (this.getActiveTab())
		{
			this.getActiveTab().deselect();
		}

		this.activeTab = newActiveTab;
		newActiveTab.select();

		if (!newActiveTab.isRendered())
		{
			newActiveTab.render();
		}

		this.focusSearch();

		if (this.shouldFocusOnFirst())
		{
			this.focusOnFirstNode();
		}
		else
		{
			this.clearNodeFocus();
		}

		return newActiveTab;
	}

	/**
	 * @private
	 */
	insertTab(tab: Tab): void
	{
		tab.renderLabel();
		Dom.append(tab.getLabelContainer(), this.getLabelsContainer());
		Dom.append(tab.getContainer(), this.getTabContentsContainer());
	}

	selectFirstTab(onlyVisible = true): ?Tab
	{
		for (let i = 0; i < this.getTabs().length; i++)
		{
			const tab = this.getTabs()[i];
			if (onlyVisible === false || tab.isVisible())
			{
				return this.selectTab(tab.getId());
			}
		}

		if (this.isDropdownMode())
		{
			return this.selectTab(this.getRecentTab().getId());
		}

		return null;
	}

	selectLastTab(onlyVisible = true): ?Tab
	{
		for (let i = this.getTabs().length - 1; i >= 0; i--)
		{
			const tab = this.getTabs()[i];
			if (onlyVisible === false || tab.isVisible())
			{
				return this.selectTab(tab.getId());
			}
		}

		if (this.isDropdownMode())
		{
			return this.selectTab(this.getRecentTab().getId());
		}

		return null;
	}

	getActiveTab(): ?Tab
	{
		return this.activeTab;
	}

	getNextTab(onlyVisible = true): ?Tab
	{
		let nextTab = null;
		let activeFound = false;
		for (let i =  0; i < this.getTabs().length; i++)
		{
			const tab = this.getTabs()[i];
			if (onlyVisible && !tab.isVisible())
			{
				continue;
			}

			if (tab === this.getActiveTab())
			{
				activeFound = true;
			}
			else if (activeFound)
			{
				nextTab = tab;
				break;
			}
		}

		return nextTab;
	}

	getPreviousTab(onlyVisible = true): ?Tab
	{
		let previousTab = null;
		let activeFound = false;
		for (let i = this.getTabs().length - 1; i >= 0; i--)
		{
			const tab = this.getTabs()[i];
			if (onlyVisible && !tab.isVisible())
			{
				continue;
			}

			if (tab === this.getActiveTab())
			{
				activeFound = true;
			}
			else if (activeFound)
			{
				previousTab = tab;
				break;
			}
		}

		return previousTab;
	}

	removeTab(id: string): void
	{
		const tab = this.getTab(id);
		if (!tab)
		{
			return;
		}

		tab.removeNodes();

		this.tabs = this.tabs.filter((el: Tab) => tab.getId() !== el.getId());

		Dom.remove(tab.getLabelContainer(), this.getLabelsContainer());
		Dom.remove(tab.getContainer(), this.getTabContentsContainer());

		this.selectFirstTab();
	}

	getItem(item: ItemId | Item | ItemOptions): ?Item
	{
		let id = null;
		let entityId = null;

		if (Type.isArray(item) && item.length === 2)
		{
			[entityId, id] = item;
		}
		else if (item instanceof Item)
		{
			id = item.getId();
			entityId = item.getEntityId();
		}
		else if (Type.isObjectLike(item))
		{
			({ id, entityId } = item);
		}

		const entityItems = this.getEntityItems(entityId);
		if (entityItems)
		{
			return entityItems.get(String(id)) || null;
		}

		return null;
	}

	getItems(): Map<string, Map<string, Item>>
	{
		return this.items;
	}

	getSelectedItems(): Item[]
	{
		return Array.from(this.selectedItems);
	}

	setPreselectedItems(itemIds: ItemId[]): void
	{
		this.preselectedItems = this.validateItemIds(itemIds);
	}

	getPreselectedItems(): ItemId[]
	{
		return this.preselectedItems;
	}

	setUndeselectedItems(itemIds: ItemId[]): void
	{
		this.undeselectedItems = this.validateItemIds(itemIds);
	}

	getUndeselectedItems()
	{
		return this.undeselectedItems;
	}

	/**
	 * @private
	 */
	validateItemIds(itemIds: ItemId[]): ItemId[]
	{
		if (!Type.isArrayFilled(itemIds))
		{
			return [];
		}

		const result = [];
		itemIds.forEach((itemId: ItemId) => {
			if (!Type.isArray(itemId) || itemId.length !== 2)
			{
				return;
			}

			const [entityId, id] = itemId;

			if (Type.isStringFilled(entityId) && (Type.isStringFilled(id) || Type.isNumber(id)))
			{
				result.push(itemId);
			}
		});

		return result;
	}

	getEntityItems(entityId: string): Map<string, Item> | null
	{
		return this.items.get(entityId) || null;
	}

	addItem(options: ItemOptions): Item
	{
		if (!Type.isPlainObject(options))
		{
			throw new Error('EntitySelector.addItem: wrong item options.');
		}

		let item = this.getItem(options);
		if (!item)
		{
			item = new Item(options);

			const undeselectable = this.getUndeselectedItems().some((itemId: ItemId) => {
				return itemId[0] === item.getEntityId() && itemId[1] === item.getId();
			});

			if (undeselectable)
			{
				item.setDeselectable(false);
			}

			item.setDialog(this);

			const entity = this.getEntity(item.getEntityId());
			if (entity === null)
			{
				this.addEntity({ id: item.getEntityId() });
			}

			let entityItems = this.items.get(item.getEntityId());
			if (!entityItems)
			{
				entityItems = new Map();
				this.items.set(item.getEntityId(), entityItems);
			}

			entityItems.set(String(item.getId()), item);

			if (item.isSelected())
			{
				this.handleItemSelect(item);
			}
		}

		let tabs = [];
		if (Type.isArray(options.tabs))
		{
			tabs = options.tabs;
		}
		else if (Type.isStringFilled(options.tabs))
		{
			tabs = [options.tabs];
		}

		const children = Type.isArray(options.children) ? options.children : [];

		tabs.forEach((tabId) => {
			const tab = this.getTab(tabId);
			if (tab)
			{
				const itemNode = tab.getRootNode().addItem(item, options.nodeOptions);
				itemNode.addChildren(children);
			}
		});

		return item;
	}

	removeItem(item: Item | ItemOptions): ?Item
	{
		item = this.getItem(item);
		if (item)
		{
			const entityItems = this.getEntityItems(item.getEntityId());
			if (entityItems)
			{
				entityItems.delete(item.getId());
				if (entityItems.size === 0)
				{
					this.items.delete(item.getEntityId());
				}
			}

			item.getNodes().forEach((node: ItemNode) => {
				node.getParentNode().removeChild(node);
			});
		}

		return item;
	}

	deselectAll(): void
	{
		this.getSelectedItems().forEach((item: Item) => {
			item.deselect();
		});
	}

	isMultiple(): boolean
	{
		return this.multiple;
	}

	setHideOnSelect(flag): void
	{
		if (Type.isBoolean(flag))
		{
			this.hideOnSelect = flag;
		}
	}

	shouldHideOnSelect(): boolean
	{
		if (this.hideOnSelect !== null)
		{
			return this.hideOnSelect;
		}

		return !this.isMultiple();
	}

	setHideOnDeselect(flag): void
	{
		if (Type.isBoolean(flag))
		{
			this.hideOnDeselect = flag;
		}
	}

	shouldHideOnDeselect(): boolean
	{
		if (this.hideOnDeselect !== null)
		{
			return this.hideOnDeselect;
		}

		return false;
	}

	addEntity(entity: Entity | EntityOptions)
	{
		if (Type.isPlainObject(entity))
		{
			entity = new Entity(entity);
		}

		if (!(entity instanceof Entity))
		{
			throw new Error('EntitySelector: an entity must be an instance of EntitySelector.Entity.');
		}

		if (this.hasEntity(entity.getId()))
		{
			console.error(`EntitySelector: the "${entity.getId()}" entity is already existed.`);
			return;
		}

		this.entities.push(entity);
	}

	getEntity(id: string): ?Entity
	{
		return this.getEntities().find((entity: Entity) => entity.getId() === id) || null;
	}

	hasEntity(id: string): boolean
	{
		return this.getEntities().some((entity: Entity) => entity.getId() === id);
	}

	getEntities(): Entity[]
	{
		return this.entities;
	}

	removeEntity(id: string): void
	{
		const index = this.getEntities().find((entity: Entity) => entity.getId() === id);
		if (index >= 0)
		{
			this.getEntities().splice(index, 1);
		}
	}

	getTagSelector(): ?TagSelector
	{
		return this.tagSelector;
	}

	getTagSelectorMode(): ?TagSelectorMode
	{
		return this.tagSelectorMode;
	}

	isTagSelectorInside(): boolean
	{
		return this.getTagSelector() && this.getTagSelectorMode() === TagSelectorMode.INSIDE;
	}

	isTagSelectorOutside(): boolean
	{
		return this.getTagSelector() && this.getTagSelectorMode() === TagSelectorMode.OUTSIDE;
	}

	getTagSelectorQuery()
	{
		return this.getTagSelector() ? this.getTagSelector().getTextBoxValue() : '';
	}

	setTagSelector(tagSelector: TagSelector): void
	{
		this.tagSelector = tagSelector;

		this.tagSelector.subscribe('onInput', Runtime.debounce(this.handleTagSelectorInput, 200, this));
		this.tagSelector.subscribe('onAddButtonClick', this.handleTagSelectorAddButtonClick.bind(this));
		this.tagSelector.subscribe('onTagRemove', this.handleTagSelectorTagRemove.bind(this));
		this.tagSelector.subscribe('onAfterTagRemove', this.handleTagSelectorAfterTagRemove.bind(this));
		this.tagSelector.subscribe('onAfterTagAdd', this.handleTagSelectorAfterTagAdd.bind(this));
		this.tagSelector.subscribe('onContainerClick', this.handleTagSelectorClick.bind(this));

		this.tagSelector.setDialog(this);
	}

	focusSearch(): void
	{
		if (this.getTagSelector())
		{
			if (this.getActiveTab() !== this.getSearchTab())
			{
				this.getTagSelector().clearTextBox();
			}

			this.getTagSelector().focusTextBox();
		}
	}

	clearSearch(): void
	{
		if (this.getTagSelector())
		{
			this.getTagSelector().clearTextBox();
		}
	}

	getLoader(): Loader
	{
		if (this.loader === null)
		{
			this.loader = new Loader({
				target: this.getTabsContainer(),
				size: 100
			});
		}

		return this.loader;
	}

	showLoader(): void
	{
		this.getLoader().show();
	}

	hideLoader(): void
	{
		if (this.loader !== null)
		{
			this.getLoader().hide();
		}
	}

	destroyLoader(): void
	{
		if (this.loader !== null)
		{
			this.getLoader().destroy();
		}

		this.loader = null;
	}

	handleTagSelectorInput(): void
	{
		if (this.getTagSelectorMode() === TagSelectorMode.OUTSIDE && !this.isOpen())
		{
			this.show();
		}

		const query = this.getTagSelector().getTextBoxValue();
		this.search(query);
	}

	handleTagSelectorAddButtonClick(): void
	{
		this.show();
	}

	handleTagSelectorTagRemove(event: BaseEvent): void
	{
		const { tag } = event.getData();

		const item = this.getItem({ id: tag.getId(), entityId: tag.getEntityId() });
		if (item)
		{
			item.deselect();
		}

		this.focusSearch();
	}

	handleTagSelectorAfterTagRemove(): void
	{
		this.adjustByTagSelector();
	}

	handleTagSelectorAfterTagAdd(): void
	{
		this.adjustByTagSelector();
	}

	handleTagSelectorClick(): void
	{
		this.focusSearch();
	}

	adjustByTagSelector(): void
	{
		if (this.getTagSelectorMode() === TagSelectorMode.OUTSIDE)
		{
			this.adjustPosition();
		}
		else if (this.getTagSelectorMode() === TagSelectorMode.INSIDE)
		{
			const newTagSelectorHeight = this.getTagSelector().calcHeight();
			if (newTagSelectorHeight > 0)
			{
				const offset = newTagSelectorHeight - (this.tagSelectorHeight || this.getTagSelector().getMinHeight());
				this.tagSelectorHeight = newTagSelectorHeight;
				if (offset !== 0)
				{
					const height = this.getHeight();
					this.setHeight(height + offset).then(() => {
						this.adjustPosition();
					});
				}
			}
		}
	}

	observeTabOverlapping()
	{
		const observer = new MutationObserver(() => {
			if (this.getTabs().some((tab: Tab) => tab.isVisible()))
			{
				const left = parseInt(this.getPopup().getPopupContainer().style.left, 10);
				if (left < this.getMinLabelWidth())
				{
					Dom.style(this.getPopup().getPopupContainer(), 'left', `${this.getMinLabelWidth()}px`);
				}
			}
		});

		observer.observe(this.getPopup().getPopupContainer(), {
			attributes: true,
			attributeFilter: ['style']
		});
	}

	/**
	 * @internal
	 */
	handleItemSelect(item: Item, animate: boolean = true)
	{
		if (!this.isMultiple())
		{
			this.deselectAll();
		}

		if (this.getTagSelector() && (this.isMultiple() || this.isTagSelectorOutside()))
		{
			const tag = item.createTag();
			tag.animate = animate;
			this.getTagSelector().addTag(tag);
		}

		this.selectedItems.add(item);
	}

	/**
	 * @internal
	 */
	handleItemDeselect(item: Item)
	{
		this.selectedItems.delete(item);
	}

	handlePopupAfterShow(): void
	{
		this.focusSearch();
		this.adjustByTagSelector();

		this.emit('onShow');
	}

	handlePopupFirstShow(): void
	{
		this.emit('onFirstShow');

		requestAnimationFrame(() => {
			requestAnimationFrame(() => {
				Dom.addClass(this.getPopup().getPopupContainer(), 'ui-selector-popup-container');
			});
		});

		this.observeTabOverlapping();
	}

	handlePopupClose(): void
	{
		if (this.isTagSelectorOutside())
		{
			if (this.getActiveTab() && this.getActiveTab() === this.getSearchTab())
			{
				this.selectFirstTab();
			}

			this.getTagSelector().clearTextBox();
			this.getTagSelector().showAddButton();
			this.getTagSelector().hideTextBox();
		}

		this.emit('onHide');
	}

	handlePopupDestroy(): void
	{
		this.destroy();
	}

	handleLabelsMouseEnter(): void
	{
		const rect = Dom.getPosition(this.getLabelsContainer());
		const freeSpace = rect.right;

		if (freeSpace > this.getMinLabelWidth())
		{
			Dom.removeClass(this.getLabelsContainer(), 'ui-selector-tab-labels--animate-hide');
			Dom.addClass(this.getLabelsContainer(), 'ui-selector-tab-labels--animate-show');

			Dom.style(this.getLabelsContainer(), 'max-width', `${Math.min(freeSpace, this.getMaxLabelWidth())}px`);
			Animation.handleTransitionEnd(this.getLabelsContainer(), 'max-width').then(() => {
				Dom.removeClass(this.getLabelsContainer(), 'ui-selector-tab-labels--animate-show');
				Dom.addClass(this.getLabelsContainer(), 'ui-selector-tab-labels--active');
			});
		}
		else
		{
			Dom.addClass(this.getLabelsContainer(), 'ui-selector-tab-labels--active');
		}
	}

	handleLabelsMouseLeave(): void
	{
		Dom.addClass(this.getLabelsContainer(), 'ui-selector-tab-labels--animate-hide');
		Dom.removeClass(this.getLabelsContainer(), 'ui-selector-tab-labels--animate-show');
		Dom.removeClass(this.getLabelsContainer(), 'ui-selector-tab-labels--active');

		Animation.handleTransitionEnd(this.getLabelsContainer(), 'max-width').then(() => {
			Dom.removeClass(this.getLabelsContainer(), 'ui-selector-tab-labels--animate-hide');
		});

		Dom.style(this.getLabelsContainer(), 'max-width', null);
	}

	show(): void
	{
		this.load();
		this.getPopup().show();
	}

	hide(): void
	{
		this.getPopup().close();
	}

	destroy(): void
	{
		if (this.destroying)
		{
			return;
		}

		this.destroying = true;
		this.emit('onDestroy');

		instances.delete(this.getId());
		if (this.isRendered())
		{
			this.getPopup().destroy();
		}

		for (const property in this)
		{
			if (this.hasOwnProperty(property))
			{
				delete this[property];
			}
		}

		Object.setPrototypeOf(this, null);
	}

	isOpen(): boolean
	{
		return this.popup && this.popup.isShown();
	}

	setTargetNode(node: Element | { left: number, top: number } | null | MouseEvent): void
	{
		if (!Type.isDomNode(node) && !Type.isNull(node) && !Type.isObject(node))
		{
			return;
		}

		this.targetNode = node;

		if (this.isRendered())
		{
			this.getPopup().setBindElement(this.targetNode);
			this.getPopup().adjustPosition();
		}
	}

	getTargetNode(): ?HTMLElement
	{
		if (this.targetNode === null)
		{
			if (this.getTagSelectorMode() === TagSelectorMode.OUTSIDE)
			{
				return this.getTagSelector().getOuterContainer();
			}
		}

		return this.targetNode;
	}

	adjustPosition()
	{
		if (this.isRendered())
		{
			this.getPopup().adjustPosition();
		}
	}

	setAutoHide(enable: boolean)
	{
		if (Type.isBoolean(enable))
		{
			this.autoHide = enable;
			if (this.isRendered())
			{
				this.getPopup().setAutoHide(enable);
			}
		}
	}

	isAutoHide(): boolean
	{
		return this.autoHide;
	}

	setHideByEsc(enable: boolean): void
	{
		if (Type.isBoolean(enable))
		{
			this.hideByEsc = enable;
			if (this.isRendered())
			{
				this.getPopup().setClosingByEsc(enable);
			}
		}
	}

	shouldHideByEsc(): boolean
	{
		return this.hideByEsc;
	}

	freeze(): void
	{
		if (this.isFrozen())
		{
			return;
		}

		this.frozenProps = {
			autoHide: this.isAutoHide(),
			hideByEsc: this.shouldHideByEsc(),
		};

		this.setAutoHide(false);
		this.setHideByEsc(false);

		this.getNavigation().disable();
		Dom.addClass(this.getContainer(), 'ui-selector-dialog--freeze');

		this.frozen = true;
	}

	unfreeze(): void
	{
		if (!this.isFrozen())
		{
			return;
		}

		this.setAutoHide(this.frozenProps.autoHide !== false);
		this.setHideByEsc(this.frozenProps.hideByEsc !== false);

		this.getNavigation().enable();
		Dom.removeClass(this.getContainer(), 'ui-selector-dialog--freeze');

		this.frozen = false;
	}

	isFrozen(): boolean
	{
		return this.frozen;
	}

	getWidth(): number
	{
		return this.width;
	}

	setWidth(width: number)
	{
		if (Type.isNumber(width) && width > 0)
		{
			this.width = width;
			if (this.isRendered())
			{
				Dom.style(this.getContainer(), 'width', `${width}px`);
			}
		}
	}

	getHeight(): number
	{
		return this.height;
	}

	setHeight(height: number): Promise
	{
		if (Type.isNumber(height) && height > 0)
		{
			this.height = height;
			if (this.isRendered())
			{
				Dom.style(this.getContainer(), 'height', `${height}px`);
				return Animation.handleTransitionEnd(this.getContainer(), 'height');
			}
			else
			{
				return Promise.resolve();
			}
		}
		else
		{
			return Promise.resolve();
		}
	}

	getOffsetLeft(): number
	{
		return this.offsetLeft;
	}

	setOffsetLeft(offset: number): void
	{
		if (Type.isNumber(offset) && offset >= 0)
		{
			this.offsetLeft = offset;
			if (this.isRendered())
			{
				this.getPopup().setOffset({ offsetLeft: offset });
				this.adjustPosition();
			}
		}
	}

	getOffsetTop(): number
	{
		return this.offsetTop;
	}

	setOffsetTop(offset: number): void
	{
		if (Type.isNumber(offset) && offset >= 0)
		{
			this.offsetTop = offset;
			if (this.isRendered())
			{
				this.getPopup().setOffset({ offsetTop: offset });
				this.adjustPosition();
			}
		}
	}

	getZindex(): number
	{
		return this.getPopup().getZindex();
	}

	setZindex(index: number): void
	{
		if ((Type.isNumber(index) && index > 0) || index === null)
		{
			this.zIndex = index;

			if (this.isRendered())
			{
				this.getPopup().params.zIndexAbsolute = index !== null ? index : 0;
				this.adjustPosition();
			}
		}
	}

	isCacheable(): boolean
	{
		return this.cacheable;
	}

	setCacheable(cacheable: boolean): void
	{
		if (Type.isBoolean(cacheable))
		{
			this.cacheable = cacheable;
			if (this.isRendered())
			{
				this.getPopup().setCacheable(cacheable);
			}
		}
	}

	shouldFocusOnFirst(): boolean
	{
		return this.focusOnFirst;
	}

	setFocusOnFirst(flag: boolean): void
	{
		if (Type.isBoolean(flag))
		{
			this.focusOnFirst = flag;
		}
	}

	isDropdownMode(): boolean
	{
		return this.dropdownMode;
	}

	setDropdownMode(flag: boolean): void
	{
		if (Type.isBoolean(flag))
		{
			this.dropdownMode = flag;
			this.getRecentTab().setVisible(!flag);
		}
	}

	getMaxLabelWidth(): number
	{
		return this.maxLabelWidth;
	}

	getMinLabelWidth(): number
	{
		return this.minLabelWidth;
	}

	getPopup(): Popup
	{
		if (this.popup !== null)
		{
			return this.popup;
		}

		this.getTabs().forEach((tab: Tab) => {
			this.insertTab(tab);
		});

		this.popup = new Popup(Object.assign({
			contentPadding: 0,
			padding: 0,
			offsetTop: this.getOffsetTop(),
			offsetLeft: this.getOffsetLeft(),
			zIndexAbsolute: this.zIndex,
			animation: {
				showClassName: 'ui-selector-popup-animation-show',
				closeClassName: 'ui-selector-popup-animation-close',
				closeAnimationType: 'animation'
			},
			bindElement: this.getTargetNode(),
			bindOptions: {
				forceBindPosition: true
			},
			autoHide: this.isAutoHide(),
			closeByEsc: this.shouldHideByEsc(),
			cacheable: this.isCacheable(),
			events: {
				onFirstShow: this.handlePopupFirstShow.bind(this),
				onAfterShow: this.handlePopupAfterShow.bind(this),
				onClose: this.handlePopupClose.bind(this),
				onDestroy: this.handlePopupDestroy.bind(this)
			},
			content: this.getContainer()
		}, this.popupOptions));

		this.rendered = true;

		this.selectFirstTab();

		return this.popup;
	}

	getContainer(): HTMLElement
	{
		return this.cache.remember('container', () => {

			let searchContainer = '';
			if (this.getTagSelectorMode() === TagSelectorMode.INSIDE)
			{
				searchContainer = Tag.render`<div class="ui-selector-search"></div>`;

				this.getTagSelector().renderTo(searchContainer);
			}

			return Tag.render`
				<div class="ui-selector-dialog" style="width:${this.getWidth()}px; height:${this.getHeight()}px;">
					${searchContainer}
					${this.getTabsContainer()}
					${this.getFooterContainer()}
				</div>
			`;
		});
	}

	getTabsContainer(): HTMLElement
	{
		return this.cache.remember('tabs-container', () => {
			return Tag.render`
				<div class="ui-selector-tabs">
					${this.getTabContentsContainer()}
					${this.getLabelsContainer()}
				</div>
			`;
		});
	}

	getTabContentsContainer(): HTMLElement
	{
		return this.cache.remember('tab-contents', () => {
			return Tag.render`<div class="ui-selector-tab-contents"></div>`;
		});
	}

	getLabelsContainer(): HTMLElement
	{
		return this.cache.remember('labels-container', () => {
			return Tag.render`
				<div 
					class="ui-selector-tab-labels"
					onmouseenter="${this.handleLabelsMouseEnter.bind(this)}"
					onmouseleave="${this.handleLabelsMouseLeave.bind(this)}"
				></div>
			`;
		});
	}

	getFooterContainer(): HTMLElement
	{
		return this.cache.remember('footer', () => {
			const footer = this.getFooter() && this.getFooter().render();

			return Tag.render`
				<div class="ui-selector-footer">${footer ? footer : ''}</div>
			`;
		});
	}

	isRendered(): boolean
	{
		return this.rendered;
	}

	load(): void
	{
		if (this.loadState !== LoadState.UNSENT || !this.hasDynamicLoad())
		{
			return;
		}

		if (this.getTagSelector())
		{
			this.getTagSelector().lock();
		}

		setTimeout(() => {
			if (this.isLoading())
			{
				this.showLoader();
			}
		}, 400);

		this.loadState = LoadState.LOADING;

		Ajax.runAction('ui.entityselector.load', {
				json: {
					dialog: this
				},
				getParameters: {
					context: this.getContext()
				}
			})
			.then((response) => {
				if (response && response.data && Type.isPlainObject(response.data.dialog))
				{
					this.loadState = LoadState.DONE;

					const entities =
						Type.isArrayFilled(response.data.dialog.entities)
							? response.data.dialog.entities
							: []
					;

					entities.forEach((entityOptions: EntityOptions) => {
						const entity = this.getEntity(entityOptions.id);
						if (entity)
						{
							entity.setDynamicSearch(entityOptions.dynamicSearch);
						}
					});

					this.setOptions(response.data.dialog);

					this.getPreselectedItems().forEach((preselectedItem: ItemId) => {
						const item = this.getItem(preselectedItem);
						if (item)
						{
							item.select(true);
						}
					});

					const recentItems = response.data.dialog.recentItems;
					if (Type.isArray(recentItems))
					{
						recentItems.forEach((recentItem: ItemId) => {
							const item = this.getItem(recentItem);
							if (item)
							{
								this.getRecentTab().getRootNode().addItem(item);
							}
						});
					}

					if (!this.getRecentTab().getRootNode().hasChildren() && this.getRecentTab().getStub())
					{
						this.getRecentTab().getStub().show();
					}

					if (this.getTagSelector())
					{
						this.getTagSelector().unlock();
					}

					if (this.isRendered() && !this.getActiveTab())
					{
						this.selectFirstTab();
					}

					this.focusSearch();
					this.destroyLoader();

					if (this.shouldFocusOnFirst())
					{
						this.focusOnFirstNode();
					}
				}
			})
			.catch((error) => {
				this.loadState = LoadState.UNSENT;

				if (this.getTagSelector())
				{
					this.getTagSelector().unlock();
				}

				this.focusSearch();
				this.destroyLoader();

				console.error(error);
			});
	}

	hasDynamicLoad(): boolean
	{
		return this.getEntities().some((entity: Entity) => entity.hasDynamicLoad());
	}

	hasDynamicSearch(): boolean
	{
		return this.getEntities().some((entity: Entity) => {
			return entity.isSearchable() && entity.hasDynamicSearch();
		});
	}

	isLoaded(): boolean
	{
		return this.loadState === LoadState.DONE;
	}

	isLoading(): boolean
	{
		return this.loadState === LoadState.LOADING;
	}

	search(queryString: string): void
	{
		const query = Type.isStringFilled(queryString) ? queryString.trim() : '';
		if (!Type.isStringFilled(query))
		{
			this.selectFirstTab();
			if (this.getSearchTab())
			{
				this.getSearchTab().clearResults();
			}
		}
		else if (this.getSearchTab())
		{
			this.selectTab(this.getSearchTab().getId());
			this.getSearchTab().search(query);
		}

		this.emit('onSearch', { query });
	}

	saveRecentItem(item: Item): void
	{
		if (this.getContext() === null || !item.isSaveable())
		{
			return;
		}

		this.recentItemsToSave.push(item);
		this.saveRecentItemsWithDebounce();
	}

	saveRecentItems(): void
	{
		if (!Type.isArrayFilled(this.recentItemsToSave))
		{
			return;
		}

		Ajax.runAction('ui.entityselector.saveRecentItems', {
				json: {
					dialog: this,
					recentItems: this.recentItemsToSave
				},
				getParameters: {
					context: this.getContext()
				}
			})
			.then((response) => {

			})
			.catch((error) => {
				console.error(error);
			});

		this.recentItemsToSave = [];
	}

	getFocusedNode(): ?ItemNode
	{
		return this.focusedNode;
	}

	clearNodeFocus(): void
	{
		if (this.focusedNode)
		{
			this.focusedNode.unfocus();
			this.focusedNode = null;
		}
	}

	focusOnFirstNode(): ?ItemNode
	{
		if (this.getActiveTab())
		{
			const itemNode = this.getActiveTab().getRootNode().getFirstChild();
			if (itemNode)
			{
				itemNode.focus();

				return itemNode;
			}
		}

		return null;
	}

	handleItemNodeFocus(event: BaseEvent): void
	{
		const { node } = event.getData();
		if (this.focusedNode === node)
		{
			return;
		}

		this.clearNodeFocus();

		this.focusedNode = node;
	}

	handleItemNodeUnfocus(): void
	{
		this.clearNodeFocus();
	}

	toJSON()
	{
		return {
			id: this.getId(),
			context: this.getContext(),
			entities: this.getEntities(),
			preselectedItems: this.getPreselectedItems()
		};
	}
}