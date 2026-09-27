<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

class CreateUser extends Command
{
    protected $signature = 'app:create-user {login : 아이디(예: admin) 또는 이메일} {--name=관리자} {--password= : 생략하면 입력을 요청한다}';

    protected $description = '로그인 계정을 만들거나 비밀번호를 재설정한다';

    public function handle(): int
    {
        $login = $this->argument('login');
        $isEmail = str_contains($login, '@');
        $password = $this->option('password') ?: password('비밀번호', required: true);

        $validator = Validator::make(
            ['login' => $login, 'password' => $password],
            [
                'login' => $isEmail ? ['required', 'email'] : ['required', 'alpha_dash', 'max:50'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // 아이디 계정은 email 컬럼(필수·유일)에 내부용 주소를 넣는다
        $user = $isEmail
            ? User::updateOrCreate(['email' => $login], ['name' => $this->option('name'), 'password' => $password])
            : User::updateOrCreate(['username' => $login], [
                'name' => $this->option('name'), 'email' => $login.'@localhost', 'password' => $password,
            ]);

        $this->info(($user->wasRecentlyCreated ? '계정을 만들었습니다: ' : '비밀번호를 재설정했습니다: ').$login);

        return self::SUCCESS;
    }
}
