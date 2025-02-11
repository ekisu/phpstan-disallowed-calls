<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Usages;

use PHPStan\File\FileHelper;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Spaze\PHPStan\Rules\Disallowed\DisallowedNamespaceFactory;
use Spaze\PHPStan\Rules\Disallowed\DisallowedNamespaceHelper;
use Spaze\PHPStan\Rules\Disallowed\IsAllowedFileHelper;

class NamespaceUsagesTest extends RuleTestCase
{

	protected function getRule(): Rule
	{
		return new NamespaceUsages(
			new DisallowedNamespaceHelper(new IsAllowedFileHelper(new FileHelper(__DIR__))),
			new DisallowedNamespaceFactory(),
			[
				[
					'namespace' => 'Framew*rk\Some*',
					'message' => 'no framework some',
					'allowIn' => [
						'../src/disallowed-allowed/*.php',
						'../src/*-allow/*.*',
					],
				],
				[
					'namespace' => 'Inheritance\Base',
					'message' => 'no inheritance Base',
					'allowIn' => [
						'../src/disallowed-allowed/*.php',
						'../src/*-allow/*.*',
					],
				],
				[
					'namespace' => 'Inheritance\Sub',
					'message' => 'no sub',
					'allowIn' => [
						'../src/disallowed-allowed/*.php',
						'../src/*-allow/*.*',
					],
				],
				[
					'namespace' => 'Waldo\Quux\Blade',
					'message' => 'no blade',
					'allowIn' => [
						'../src/disallowed-allowed/*.php',
						'../src/*-allow/*.*',
					],
				],
				[
					'namespace' => 'Waldo\Foo\bar',
					'message' => 'no FooBar',
					'allowIn' => [
						'../src/disallowed-allowed/*.php',
						'../src/*-allow/*.*',
					],
				],
				[
					'namespace' => 'Traits\TestTrait',
					'message' => 'no TestTrait',
					'allowIn' => [
						'../src/disallowed-allowed/*.php',
						'../src/*-allow/*.*',
					],
				],
			]
		);
	}


	public function testRule(): void
	{
		// Based on the configuration above, in this file:
		$this->analyse([__DIR__ . '/../src/disallowed/namespaceUsages.php'], [
			[
				// Based on the configuration above, in this file:
				'Namespace Framework\SomeInterface is forbidden, no framework some [Framework\SomeInterface matches Framew*rk\Some*]',
				// on this line:
				6,
			],
			[
				'Namespace Inheritance\Base is forbidden, no inheritance Base',
				7,
			],
			[
				'Namespace Inheritance\Sub is forbidden, no sub',
				8,
			],
			[
				'Namespace Traits\TestTrait is forbidden, no TestTrait',
				9,
			],
			[
				'Namespace Waldo\Foo\Bar is forbidden, no FooBar [Waldo\Foo\Bar matches Waldo\Foo\bar]',
				10,
			],
			[
				'Namespace Waldo\Quux\blade is forbidden, no blade [Waldo\Quux\blade matches Waldo\Quux\Blade]',
				11,
			],
			[
				'Namespace Inheritance\Base is forbidden, no inheritance Base',
				13,
			],
			[
				'Namespace Framework\SomeInterface is forbidden, no framework some [Framework\SomeInterface matches Framew*rk\Some*]',
				13,
			],
			[
				'Namespace Traits\TestTrait is forbidden, no TestTrait',
				16,
			],
			[
				'Namespace Waldo\Quux\blade is forbidden, no blade [Waldo\Quux\blade matches Waldo\Quux\Blade]',
				23,
			],
			[
				'Namespace Inheritance\Sub is forbidden, no sub',
				31,
			],
			[
				'Namespace Waldo\Foo\Bar is forbidden, no FooBar [Waldo\Foo\Bar matches Waldo\Foo\bar]',
				37,
			],
		]);
		$this->analyse([__DIR__ . '/../src/disallowed-allow/namespaceUsages.php'], []);
	}

}
