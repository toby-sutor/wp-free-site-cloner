<?php
/**
 * Search-replace tests.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Search_Replace
 */
class SearchReplaceTest extends FSC_Test_Case {

	private function sr() {
		return new FSC_Search_Replace(
			FSC_Search_Replace::build_pairs(
				array( array( 'http://old.test', 'https://new.example.org/site' ) ),
				array( array( '/var/www/old/', '/srv/new/' ) )
			)
		);
	}

	public function test_pairs_cover_variants_longest_first() {
		$pairs = $this->sr()->pairs();
		$this->assertSame( 'https://new.example.org/site', $pairs['http://old.test'] );
		$this->assertSame( 'https://new.example.org/site', $pairs['https://old.test'] );
		$this->assertSame( '//new.example.org/site', $pairs['//old.test'] );
		$this->assertSame( 'https:\/\/new.example.org\/site', $pairs['http:\/\/old.test'] );
		$this->assertSame( '\/\/new.example.org\/site', $pairs['\/\/old.test'] );
		$this->assertSame( 'https%3A%2F%2Fnew.example.org%2Fsite', $pairs['http%3A%2F%2Fold.test'] );
		$this->assertSame( '/srv/new', $pairs['/var/www/old'] );
		$lens = array_map( 'strlen', array_keys( $pairs ) );
		$sorted = $lens;
		rsort( $sorted );
		$this->assertSame( $sorted, $lens );
	}

	public function test_like_terms_are_minimal_and_cover_all() {
		$sr    = $this->sr();
		$terms = $sr->like_terms();
		$this->assertLessThan( count( $sr->olds() ), count( $terms ) );
		foreach ( $sr->olds() as $old ) {
			$hit = false;
			foreach ( $terms as $t ) {
				$hit = $hit || false !== strpos( $old, $t );
			}
			$this->assertTrue( $hit, $old );
		}
	}

	public function test_plain_strings() {
		$sr = $this->sr();
		$this->assertSame( 'see https://new.example.org/site/page and https://new.example.org/site/x', $sr->replace( 'see http://old.test/page and https://old.test/x' ) );
		$this->assertSame( '<img src="//new.example.org/site/a.png">', $sr->replace( '<img src="//old.test/a.png">' ) );
		$this->assertSame( 'untouched', $sr->replace( 'untouched' ) );
		$this->assertSame( 42, $sr->replace( 42 ) );
		$this->assertNull( $sr->replace( null ) );
		$this->assertSame( '/srv/new/wp-content/uploads', $sr->replace( '/var/www/old/wp-content/uploads' ) );
	}

	public function test_json_escaped_and_urlencoded() {
		$sr   = $this->sr();
		$json = json_encode( array( 'url' => 'http://old.test/a/b', 'path' => '/var/www/old/x' ) );
		$this->assertSame( array( 'url' => 'https://new.example.org/site/a/b', 'path' => '/srv/new/x' ), json_decode( $sr->replace( $json ), true ) );
		$this->assertSame( 'u=https%3A%2F%2Fnew.example.org%2Fsite%2Fp', $sr->replace( 'u=' . urlencode( 'http://old.test/p' ) ) );
	}

	public function test_serialized_nested_arrays_and_lengths() {
		$sr    = $this->sr();
		$value = array(
			'home'   => 'http://old.test',
			'nested' => array(
				'deep' => array( 'img' => 'http://old.test/wp-content/uploads/a.jpg', 'n' => 5, 'f' => 1.5, 'b' => true, 'z' => null ),
				'json' => json_encode( array( 'u' => 'http://old.test/j' ) ),
			),
			'http://old.test/key' => 'keys are kept',
			'utf8'   => 'Grüße http://old.test/ü',
		);
		$out = $sr->replace( serialize( $value ) );
		$un  = unserialize( $out );
		$this->assertSame( 'https://new.example.org/site', $un['home'] );
		$this->assertSame( 'https://new.example.org/site/wp-content/uploads/a.jpg', $un['nested']['deep']['img'] );
		$this->assertSame( 1.5, $un['nested']['deep']['f'] );
		$this->assertTrue( $un['nested']['deep']['b'] );
		$this->assertNull( $un['nested']['deep']['z'] );
		$this->assertSame( array( 'u' => 'https://new.example.org/site/j' ), json_decode( $un['nested']['json'], true ) );
		$this->assertSame( 'keys are kept', $un['http://old.test/key'] );
		$this->assertSame( 'Grüße https://new.example.org/site/ü', $un['utf8'] );
	}

	public function test_serialized_in_serialized() {
		$sr    = $this->sr();
		$inner = serialize( array( 'url' => 'http://old.test/inner' ) );
		$outer = serialize( array( 'wrapped' => $inner, 'twice' => serialize( $inner ) ) );
		$un    = unserialize( $sr->replace( $outer ) );
		$this->assertSame( 'https://new.example.org/site/inner', unserialize( $un['wrapped'] )['url'] );
		$this->assertSame( 'https://new.example.org/site/inner', unserialize( unserialize( $un['twice'] ) )['url'] );
	}

	public function test_objects_incomplete_classes_private_props_and_references() {
		$sr  = $this->sr();
		// Class that does not exist here, with public, protected and private props.
		$ser = 'O:13:"Missing_Class":3:{s:3:"pub";s:19:"http://old.test/pub";s:6:"' . "\0*\0" . 'pro";s:19:"http://old.test/pro";s:18:"' . "\0Missing_Class\0" . 'pri";a:1:{i:0;s:19:"http://old.test/pri";}}';
		$this->assertNotFalse( @unserialize( $ser, array( 'allowed_classes' => false ) ) );
		$out = $sr->replace( $ser );
		$this->assertStringNotContainsString( 'old.test', $out );
		$obj = unserialize( $out, array( 'allowed_classes' => false ) );
		$this->assertInstanceOf( '__PHP_Incomplete_Class', $obj );
		$arr = (array) $obj;
		$this->assertSame( 'https://new.example.org/site/pub', $arr['pub'] );
		$this->assertSame( 'https://new.example.org/site/pro', $arr[ "\0*\0pro" ] );
		$this->assertSame( array( 'https://new.example.org/site/pri' ), $arr[ "\0Missing_Class\0pri" ] );
		// Class name is preserved (re-serialising the incomplete object keeps it).
		$this->assertStringStartsWith( 'O:13:"Missing_Class":3:', serialize( $obj ) );

		$o       = new stdClass();
		$o->url  = 'http://old.test/o';
		$o->self = 'x';
		$data    = array( 'a' => $o, 'b' => $o, 's' => 'http://old.test/s' );
		$data['r'] = &$data['s'];
		$out     = $sr->replace( serialize( $data ) );
		$un      = unserialize( $out );
		$this->assertSame( 'https://new.example.org/site/o', $un['a']->url );
		$this->assertSame( $un['a'], $un['b'] );
		$this->assertSame( 'https://new.example.org/site/s', $un['r'] );
	}

	public function test_custom_serialized_c_payload() {
		$sr  = $this->sr();
		$ser = serialize( new ArrayObject( array( 'u' => 'http://old.test/ao' ) ) );
		$out = $sr->replace( $ser );
		$this->assertStringNotContainsString( 'old.test', $out );
		$un = unserialize( $out );
		$this->assertSame( 'https://new.example.org/site/ao', $un['u'] );
	}

	public function test_string_length_changes_both_directions() {
		$short = new FSC_Search_Replace( array( 'http://a-very-long-old-domain.test' => 'http://s.io' ) );
		$ser   = serialize( array( 'x' => 'http://a-very-long-old-domain.test/p', 'y' => array( 'http://a-very-long-old-domain.test' ) ) );
		$un    = unserialize( $short->replace( $ser ) );
		$this->assertSame( 'http://s.io/p', $un['x'] );
		$this->assertSame( array( 'http://s.io' ), $un['y'] );
	}

	public function test_broken_serialized_is_repaired_when_possible() {
		$sr     = $this->sr();
		$broken = 'a:1:{s:1:"u";s:5:"http://old.test/z";}';
		$out    = $sr->replace( $broken );
		$this->assertSame( array( 'u' => 'https://new.example.org/site/z' ), unserialize( $out ) );
	}

	public function test_non_serialized_lookalikes_are_plain_replaced() {
		$sr = $this->sr();
		$this->assertSame( 's:not serialized https://new.example.org/site;', $sr->replace( 's:not serialized http://old.test;' ) );
		$this->assertSame( 'a:b http://new', ( new FSC_Search_Replace( array( 'http://old' => 'http://new' ) ) )->replace( 'a:b http://old' ) );
	}

	public function test_serialized_false_and_scalars() {
		$sr = $this->sr();
		$this->assertSame( 'b:0;', $sr->replace( 'b:0;' ) );
		$this->assertSame( serialize( 'https://new.example.org/site' ), $sr->replace( serialize( 'http://old.test' ) ) );
	}

	public function test_new_url_containing_old_is_not_replaced_twice() {
		$sr = new FSC_Search_Replace( FSC_Search_Replace::build_pairs( array( array( 'http://site.test', 'http://site.test.local' ) ) ) );
		$this->assertSame( 'http://site.test.local/a', $sr->replace( 'http://site.test/a' ) );
		$un = unserialize( $sr->replace( serialize( array( 'http://site.test/a' ) ) ) );
		$this->assertSame( array( 'http://site.test.local/a' ), $un );
	}

	/**
	 * Build $depth levels of a:1:{i:0; ... } around one matching string.
	 */
	private function nested_serialized( $depth, $inner ) {
		return str_repeat( 'a:1:{i:0;', $depth ) . $inner . str_repeat( '}', $depth );
	}

	public function test_structural_nesting_just_below_limit_is_rewritten() {
		$sr    = $this->sr();
		$inner = 's:15:"http://old.test";';
		$data  = $this->nested_serialized( FSC_Search_Replace::MAX_STRUCT_DEPTH - 1, $inner );
		$out   = $sr->replace( $data );
		$this->assertStringContainsString( 'https://new.example.org/site', $out );
		$this->assertSame( $this->nested_serialized( FSC_Search_Replace::MAX_STRUCT_DEPTH - 1, 's:28:"https://new.example.org/site";' ), $out );
	}

	public function test_structural_nesting_above_limit_keeps_original_and_does_not_crash() {
		$sr    = $this->sr();
		$inner = 's:15:"http://old.test";';

		// Above the structural limit but still accepted by unserialize (default
		// unserialize_max_depth is 4096): walk() bails out at the limit, the
		// throw is caught like unparseable data, and the value is kept as is.
		foreach ( array( FSC_Search_Replace::MAX_STRUCT_DEPTH + 1, FSC_Search_Replace::MAX_STRUCT_DEPTH + 100, 2000 ) as $depth ) {
			$data = $this->nested_serialized( $depth, $inner );
			$this->assertTrue( FSC_Search_Replace::is_valid_serialized( $data ), "depth $depth should unserialize" );
			// Unchanged: the deep URL is not rewritten, and the process does not crash.
			$this->assertSame( $data, $sr->replace( $data ), "depth $depth should be kept as is" );
			$this->assertStringNotContainsString( 'new.example.org', $sr->replace( $data ) );
		}
	}
}
