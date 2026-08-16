<?php

class BmcFeatureTestRunner
{
    private $tests = [];
    private $assertions = 0;
    private $passed = 0;
    private $failed = 0;

    public function test($name, callable $test)
    {
        $this->tests[] = [$name, $test];
    }

    public function assertTrue($condition, $message = 'Expected condition to be true')
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function assertFalse($condition, $message = 'Expected condition to be false')
    {
        $this->assertTrue(!$condition, $message);
    }

    public function assertSame($expected, $actual, $message = '')
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $details = $message ?: 'Values are not identical';
            throw new RuntimeException(
                $details . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
            );
        }
    }

    public function assertContains($needle, $haystack, $message = '')
    {
        $this->assertions++;
        if (strpos((string) $haystack, (string) $needle) === false) {
            throw new RuntimeException($message ?: "Expected output to contain: {$needle}");
        }
    }

    public function assertNotContains($needle, $haystack, $message = '')
    {
        $this->assertions++;
        if (strpos((string) $haystack, (string) $needle) !== false) {
            throw new RuntimeException($message ?: "Expected output not to contain: {$needle}");
        }
    }

    public function assertNotEmpty($actual, $message = 'Expected value not to be empty')
    {
        $this->assertions++;
        if (empty($actual)) {
            throw new RuntimeException($message);
        }
    }

    public function invokePrivate($object, $method, array $arguments = [])
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }

    public function run()
    {
        global $wpdb, $post, $wp_query;

        foreach ($this->tests as $definition) {
            list($name, $test) = $definition;
            $startingUser = get_current_user_id();
            $startingPost = $post;
            $startingQuery = clone $wp_query;
            $startingRequest = $_REQUEST;
            $startingGet = $_GET;
            $startingPostData = $_POST;
            $startingServer = $_SERVER;

            $wpdb->query('START TRANSACTION');

            try {
                $test($this);
                $this->passed++;
                fwrite(STDOUT, "PASS  {$name}\n");
            } catch (Throwable $exception) {
                $this->failed++;
                fwrite(STDOUT, "FAIL  {$name}\n      {$exception->getMessage()}\n");
            } finally {
                $wpdb->query('ROLLBACK');
                wp_cache_flush();
                wp_set_current_user($startingUser);
                $post = $startingPost;
                $wp_query = $startingQuery;
                $_REQUEST = $startingRequest;
                $_GET = $startingGet;
                $_POST = $startingPostData;
                $_SERVER = $startingServer;
            }
        }

        fwrite(
            STDOUT,
            sprintf(
                "\n%d tests, %d assertions: %d passed, %d failed\n",
                count($this->tests),
                $this->assertions,
                $this->passed,
                $this->failed
            )
        );

        return $this->failed === 0 ? 0 : 1;
    }
}
