import { Text, Tag, Type, Loc, Dom } from 'main.core';
import type Tab from '../tabs/tab';
import BaseStub from './base-stub';

export default class DefaultStub extends BaseStub
{
	content: HTMLElement = null;

	constructor(tab: Tab, options: { [option: string]: any })
	{
		super(tab, options);
	}

	getContainer()
	{
		return this.cache.remember('container', () => {
			const subtitle = this.getOption('subtitle');
			const title = Type.isStringFilled(this.getOption('title')) ? this.getOption('title') : this.getDefaultTitle();

			const icon = this.getOption('icon') || this.getTab().getIcon('default');
			let iconOpacity = 35;
			if (Type.isNumber(this.getOption('iconOpacity')))
			{
				iconOpacity = Math.min(100, Math.max(0, this.getOption('iconOpacity')));
			}

			const iconStyle =
				Type.isStringFilled(icon)
					? `style="background-image: url('${icon}'); opacity: ${iconOpacity / 100};"`
					: ''
			;

			const arrow = this.getOption('arrow', false) && this.getTab().getDialog().getFooter() !== null;

			return Tag.render`
				<div class="ui-selector-tab-default-stub">
					<div class="ui-selector-tab-default-stub-icon"${iconStyle}></div>
					<div class="ui-selector-tab-default-stub-title">${title}</div>
					${subtitle ? Tag.render`<div class="ui-selector-tab-default-stub-subtitle">${subtitle}</div>` : ''}
					${arrow ? Tag.render`<div class="ui-selector-tab-default-stub-arrow"></div>` : ''}
				</div>
			`;
		});
	}

	getDefaultTitle()
	{
		const tabTitle = Text.encode(this.getTab().getTitle());

		return Loc.getMessage('UI_SELECTOR_TAB_STUB_TITLE').replace(/#TAB_TITLE#/, tabTitle);
	}

	render(): HTMLElement
	{
		return this.getContainer();
	}
}