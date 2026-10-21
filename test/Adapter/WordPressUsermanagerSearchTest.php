<?php declare(strict_types=1);

namespace Base3WordPress\Test\Adapter;

use Base3WordPress\Adapter\WordPressUsermanager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// WordPress and other hosts may discover PHP test files during a production build.
// Do not require PHPUnit or execute any tests merely by loading this file.
if (class_exists(TestCase::class)) {
	final class WordPressUsermanagerSearchTest extends TestCase {

		public function testInvalidPaginationIsRejectedBeforeWordPressAccess(): void {
			$manager = $this->manager();
			foreach ([[0, 0], [101, 0], [20, -1]] as [$limit, $offset]) {
				try {
					$manager->searchUsers('name', $limit, $offset);
					self::fail('Expected invalid pagination to be rejected.');
				} catch (\InvalidArgumentException) {
					self::assertTrue(true);
				}
			}
		}

		public function testBlankQueryReturnsNoUsersWithoutAccessingWordPress(): void {
			self::assertSame([], $this->manager()->searchUsers(" \t\n "));
		}

		public function testLiteralSearchIsPreparedAcrossAllIdentityFields(): void {
			$database = $this->database();
			$sql = $this->prepare($database, 'Mu%_\\ler', 12, 24, null);

			self::assertSame('prepared-sql', $sql);
			self::assertStringContainsString('SELECT DISTINCT', $database->template);
			self::assertStringContainsString('LOWER(u.user_login)', $database->template);
			self::assertStringContainsString('LOWER(u.display_name)', $database->template);
			self::assertStringContainsString('LOWER(u.user_email)', $database->template);
			self::assertStringContainsString('LOWER(first_name.meta_value)', $database->template);
			self::assertStringContainsString('LOWER(last_name.meta_value)', $database->template);
			self::assertStringContainsString('CONCAT_WS', $database->template);
			self::assertStringContainsString('ORDER BY u.display_name, u.ID LIMIT %d OFFSET %d', $database->template);
			self::assertSame(array_fill(0, 6, '%Mu\\%\\_\\\\ler%'), array_slice($database->values, 0, 6));
			self::assertSame([12, 24], array_slice($database->values, -2));
			self::assertStringNotContainsString('Mu%_\\ler', $database->template);
		}

		public function testNumericSearchUsesAnExactIdAndRejectsOverflow(): void {
			$database = $this->database();
			$this->prepare($database, '00042', 20, 0, null);
			self::assertStringContainsString('u.ID = %d', $database->template);
			self::assertSame(42, $database->values[6]);
			self::assertSame([20, 0], array_slice($database->values, -2));

			$database = $this->database();
			$this->prepare($database, '9999999999999999999999999999', 20, 0, null);
			self::assertStringNotContainsString('u.ID = %d', $database->template);
			self::assertCount(8, $database->values);
		}

		public function testMultisiteMembershipIsBoundAndNotInterpolated(): void {
			$database = $this->database();
			$membershipKey = 'wp_9_capabilities';
			$this->prepare($database, 'Anna', 20, 0, $membershipKey);
			self::assertStringContainsString('member.meta_key = %s', $database->template);
			self::assertStringNotContainsString($membershipKey, $database->template);
			self::assertSame($membershipKey, $database->values[6]);
		}

		private function manager(): WordPressUsermanager {
			return (new ReflectionClass(WordPressUsermanager::class))->newInstanceWithoutConstructor();
		}

		private function database(): object {
			return new class {
				public string $users = 'wp_users';
				public string $usermeta = 'wp_usermeta';
				public string $template = '';
				public array $values = [];

				public function esc_like(string $value): string {
					return addcslashes($value, '_%\\');
				}

				public function prepare(string $query, mixed ...$values): string {
					$this->template = $query;
					$this->values = $values;
					return 'prepared-sql';
				}
			};
		}

		private function prepare(object $database, string $query, int $limit, int $offset, ?string $membershipKey): string {
			$method = new ReflectionMethod(WordPressUsermanager::class, 'prepareSearchUsersQuery');
			return $method->invoke($this->manager(), $database, $query, $limit, $offset, $membershipKey);
		}
	}
}
