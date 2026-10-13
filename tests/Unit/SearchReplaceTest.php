<?php
/**
 * Serialized find and replace.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Export\SearchReplace;
use PHPUnit\Framework\TestCase;

/**
 * Confirms replacements keep PHP serialized lengths valid.
 *
 * @covers \BrightIdeas\Migrate\Export\SearchReplace
 */
class SearchReplaceTest extends TestCase {

	/**
	 * A longer replacement rewrites the serialized length.
	 */
	public function test_serialized_string_length_is_updated() {
		$result = SearchReplace::apply(
			's:5:"hello";',
			array(
				array(
					'from' => 'hello',
					'to'   => 'hi world',
				),
			)
		);
		$this->assertSame( 's:8:"hi world";', $result );
	}

	/**
	 * Nested string values change and the class name does not.
	 */
	public function test_nested_array_and_object_class_name() {
		$pairs  = array(
			array(
				'from' => 'hello',
				'to'   => 'xyz',
			),
		);
		$array  = SearchReplace::apply( 'a:1:{s:3:"url";s:5:"hello";}', $pairs );
		$object = SearchReplace::apply( 'O:5:"hello":1:{s:1:"a";s:5:"hello";}', $pairs );
		$this->assertSame( 'a:1:{s:3:"url";s:3:"xyz";}', $array );
		$this->assertSame( 'O:5:"hello":1:{s:1:"a";s:3:"xyz";}', $object );
	}

	/**
	 * Plain text and the JSON-escaped slash form are both replaced.
	 */
	public function test_plain_url_and_json_slash_form() {
		$pairs = array(
			array(
				'from' => 'http://old.test',
				'to'   => 'http://new.test',
			),
		);
		$this->assertSame(
			'See http://new.test/a',
			SearchReplace::apply( 'See http://old.test/a', $pairs )
		);
		$this->assertSame(
			'http:\\/\\/new.test',
			SearchReplace::apply( 'http:\\/\\/old.test', $pairs )
		);
	}

	/**
	 * A broken serialized value is returned unchanged.
	 */
	public function test_broken_serialized_value_is_left_unchanged() {
		$broken = 's:5:"hi";';
		$this->assertSame(
			$broken,
			SearchReplace::apply(
				$broken,
				array(
					array(
						'from' => 'hi',
						'to'   => 'hello',
					),
				)
			)
		);
	}
}
