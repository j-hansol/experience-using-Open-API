
<?php

namespace App\Http\Controllers\Api\V1;

use App\Lib\CryptDataS;
use App\Models\AccessToken;
use App\Models\BoardContent;
use App\Models\BoardMaster;
use App\Models\Device;
use App\Models\Notification;
use App\Models\Registry;
use App\Traits\AuthenticatesUsers;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

/**
 * @OA\Info(
 *     version="1,2",
 *     title="Trans App API",
 *     description="물류관리 어플리케이션과 연동하기 위한 API입니다. 이 문서에는 1.0과 2.0이 통합되어 있습니다. 각 버전별 태그로 분리된 API를 참고하십시오. 각 버전의 경로에 주의하세요.",
 *     @OA\Contact(
 *          email="sh.jang@......kr"
 *     ),
 *     @OA\License(
 *          name="Private"
 *     )
 * )
 */

/**
 * @OA\Server(
 *     url="http://public.......wd/api",
 *     description="개발환경"
 * )
 *
 * @OA\Server(
 *     url="http://carbis.......com/api",
 *     description="테스트서버"
 * )
 *
 * @OA\Server(
 *     url="http://main.......com/api",
 *     description="운영서버"
 * )
 */

/**
 * @OA\Tag(
 *     name="V1/user",
 *     description="사용자 인증"
 * )
 */

class ApiTokenController extends Controller
{
    use AuthenticatesUsers;

    private function getValidUser( Request $request ) {
        $user = User::where( $this->username(), $request->input( $this->username() ) )
            ->where('active', 1)
            ->get()
            ->first();

        if( $user ) {
            if( Hash::check( $request->input('password'), $user->password ) ) return $user;
            return null;
        }
        else return null;
    }

    private function getValidUserByArray( $array ) {
        $user = User::where( $this->username(), $array[$this->username()] )
            ->where('active', 1)
            ->get()
            ->first();

        if( $user ) {
            if( Hash::check( $array['password'], $user->password ) ) return $user;
            return null;
        }
        else return null;
    }

    private function getBoardList() {
        $list = BoardMaster::all();
        $data = [];

        if( $list->isEmpty() ) {
            return $data;
        }

        foreach( $list as $board ) {
            $data[] = [
                'id' => $board->id,
                'name' => $board->name
            ];
        }
        return $data;
    }

    /**
     * @brief 사용자의 단말기 정보를 가져온다.
     * @param User $user
     * @param Request $request
     * @return mixed
     */
    private function getDeviceFromRequest( User $user, Request $request ) {
        return $user->devices()
            ->where( 'device_name', $request->input('device_name' ) )
            ->where( 'device_id', hash( 'sha256', $request->input('device_id') ) )
            ->get()
            ->first();
    }

    /**
     * @brief 파리머터로 전달된 단말기 이름과 단말기 토큰이 존재하는지 여부를 판단한다.
     * @param User $user
     * @param Request $request
     * @return bool
     */
    private function isExistDevice( User $user, Request $request ) {
        return $this->getDeviceFromRequest( $user, $request ) !== null;
    }

    /**
     * @brief 회원의 엑세스 토큰을 삭제한다.
     * @param User $user
     */
    private function removeAccessToken( User $user ) {
        $user->accessToken()->delete();
    }

    private function genAccessToken( User $user ) {
        $token = $user->user_id . '-' . Str::random(60);
        AccessToken::create([
            'user_id' => $user->id,
            'token' => hash( 'sha256', $token )
        ]);

        return $token;
    }

    /**
     * @brief 전달된 단말기 정보를 죄한 개수를 참고하여 저장한다. 제한 개수에 도달한 경우 저장하지 않고 406, 저장된 경우 200을 리턴한다.
     * @param User $user
     * @param Request $request
     * @return int
     */
    private function addDevice( User $user, Request $request ) {
        $device_name = $request->input('device_name', '');
        $device_id = $request->input('device_id', '');
        $fcm_device_token = $request->input('fcm_device_token', '');

        if( empty( $fcm_device_token ) ) return 400;

        $devices = $user->devices()->get();
        $device_limit = Registry::getRegistory('user', 'device_limit')->key_value;

        if( $devices->count() >= $device_limit ) return 406;
        else {
            Device::create([
                'user_id' => $user->id,
                'device_name' => $device_name,
                'device_id' => hash('sha256', $device_id),
                'fcm_device_token' => $fcm_device_token
            ]);
            return 200;
        }
    }

    public function getRoleIdsForUser( User $user ) {
        $id = DB::table('model_has_roles' )
            ->select( 'role_id' )
            ->where('model_type', User::class )
            ->where('model_id', $user->id )
            ->get()
            ->max('role_id');
        return $id == null ? 0 : $id;
    }

    /**
     * @OA\Post(
     *     path="/v1/user/join",
     *     tags={"V1/user"},
     *     description="회원 듳록 요청을 합니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="user_id",
     *                      type="string",
     *                      description="사용자 ID (암호화된 데이터)"
     *                  ),
     *                  @OA\Property(
     *                      property="role_name",
     *                      type="string",
     *                      description="회원 유형 (차주 협력업체 중 1택)"
     *                  ),
     *                  @OA\Property(
     *                      property="name",
     *                      type="string",
     *                      description="성명"
     *                  ),
     *                  @OA\Property(
     *                      property="email",
     *                      type="string",
     *                      description="전자우편주소"
     *                  ),
     *                  @OA\Property(
     *                      property="password",
     *                      type="string",
     *                      description="비밀번호 (암호화된 데이터)"
     *                  ),
     *                  @OA\Property(
     *                      property="prefix",
     *                      type="string",
     *                      description="차량 ID 접두어 (선택)"
     *                  ),
     *                  @OA\Property(
     *                      property="license_no",
     *                      type="string",
     *                      description="사업자등록번호"
     *                  ),
     *                  @OA\Property(
     *                      property="nickname",
     *                      type="string",
     *                      description="닉네임 (선택)"
     *                  ),
     *                  @OA\Property(
     *                      property="car_no",
     *                      type="string",
     *                      description="차량번호 (선택)"
     *                  ),
     *                  @OA\Property(
     *                      property="boss_name",
     *                      type="string",
     *                      description="대표자성명 (선택)"
     *                  ),
     *                  @OA\Property(
     *                      property="company_name",
     *                      type="string",
     *                      description="상호 (선택)"
     *                  ),
     *                  @OA\Property(
     *                      property="telephone",
     *                      type="string",
     *                      description="대표전화번호 (선택)"
     *                  ),
     *                  @OA\Property(
     *                      property="fax",
     *                      type="string",
     *                      description="팩스 (선택)"
     *                  ),
     *                  @OA\Property(
     *                      property="device_name",
     *                      type="string",
     *                      description="단말기 이름"
     *                  ),
     *                  @OA\Property(
     *                      property="device_id",
     *                      type="string",
     *                      description="단말기 ID"
     *                  ),
     *                  @OA\Property(
     *                      property="fcm_device_token",
     *                      type="string",
     *                      description="FCM 단말기 토큰"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="role",
     *                      type="integer"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=406,
     *          description="수용할 수 없음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     */
    /**
     * @brief 전달된 데이터의 유효성 곰토 후 회원 등록작업을 한다.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function join( Request $request ) {
        $validator = Validator::make( $request->input(), [
            'user_id' => ['required', 'string'],
            'role_name' => ['required', 'string', Rule::in(['차주', '협력업체'])],
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'license_no' => ['required', 'string', 'regex:/\d+/'],
            'device_name' => ['required', 'string'],
            'device_id' => ['required', 'string'],
            'fcm_device_token' => ['required', 'string']
        ] );

        if( $validator->fails() ) {
            return response()->json( [
                'message' => __('api.r400'),
                'error' => $validator->getMessageBag()->toArray()
            ], 400 );
        }

        try {
            $t_user_id = CryptDataS::decrypt( $request->input('user_id') );
            $t_password = CryptDataS::decrypt( $request->input('password') );
            if( empty( $t_user_id ) || empty( $t_password ) ) {
                return response()->json([
                    'message' => __('api.r406'),
                ], 406 );
            }

            $validator = Validator::make(
                ['user_id' => $t_user_id,],
                ['user_id' => ['regex:/^[\+\d]{8,15}/', 'unique:users,user_id']]
            );
            if( $validator->fails() ) {
                return response()->json([
                    '   message' => __('api.r400'),
                    ], 400
                );
            }

            $values = $request->input();
            $values['user_id'] = $t_user_id;
            $values['password'] = $t_password;

            $user = new User();
            $user->fill( $values );
            $user->password = Hash::make( $values['password'] );
            $user->api_token = $values['user_id'] . '-' . Str::random(60);
            $user->save();

            $user->assignRole( $request->input('role_name') );
            $user->assignRole( '접근권한_게시판' );
            $user->active = 1;
            $user->save();

            $this->addDevice( $user, $request );
            $token = $this->genAccessToken( $user );

            return response()->json([
                'message' => __('api.r200'),
                'api_token' => $user->api_token,
                'access_token' => $token,
                'role' => $this->getRoleIdsForUser( $user )
            ], 200 );
        }
        catch ( \Exception $e ) {
            return response()->json(['message' => __('api.r500')], 500 );
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/updateMyInfo",
     *     tags={"V1/user"},
     *     description="회원정보(소속 오퍼레이터 그룹, 이메일주소, 닉네임)를 변경한다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string",
     *                      description="Access 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="prefix",
     *                      type="string",
     *                      description="차량 ID Prefix"
     *                  ),
     *                  @OA\Property(
     *                      property="nickname",
     *                      type="string",
     *                      description="닉네임"
     *                  ),
     *                  @OA\Property(
     *                      property="car_no",
     *                      type="string",
     *                      description="차량번호"
     *                  ),
     *                  @OA\Property(
     *                      property="email",
     *                      type="string",
     *                      description="이메일 주소"
     *                  ),
     *                  @OA\Property(
     *                      property="license_no",
     *                      type="string",
     *                      description="사업자등록번호"
     *                  ),
     *                  @OA\Property(
     *                      property="boss_name",
     *                      type="string",
     *                      description="대표자"
     *                  ),
     *                  @OA\Property(
     *                      property="company_name",
     *                      type="string",
     *                      description="상호"
     *                  ),
     *                  @OA\Property(
     *                      property="telephone",
     *                      type="string",
     *                      description="대표전"
     *                  ),
     *                  @OA\Property(
     *                      property="fax",
     *                      type="string",
     *                      description="팩스"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @brief 회원정보(소속 오퍼레이터 그룹, 이메일주소, 닉네임)를 변경한다.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateMyInfo( Request $request ) {
        $target = [ 'prefix', 'email', 'nickname', 'car_no', 'license_no', 'boss_name', 'company_name', 'telephone', 'fax' ];
        $rules = [
            'prefix' => ['string'],
            'email' => ['email'],
            'nickname' => ['string'],
            'car_no' => ['string'],
            'license_no' => ['string'],
            'boss_name' => ['string'],
            'company_name' => ['string'],
            'telephone' => ['string'],
            'fax' => ['string']
        ];
        $values = $request->input();
        foreach( $target as $t ) {
            if( !isset( $values[$t]) || empty($values[$t]) ) {
                unset( $values[$t] );
                unset( $rules[$t] );
            }
        }

        $validator = Validator::make( $values, $rules );
        if( $validator->fails() ) {
            return response()->json(['message' => __('api.r400')], 400 );
        }

        try {
            $user = $request->user();
            foreach( $target as $field ) {
                $value = $request->input( $field );
                if( !empty( $value ) ) $user->{$field} = $value;
            }
            $user->save();
            return response()->json(['message' => __('api.r200')], 200);
        }
        catch ( \Exception $e ) {
            return response()->json(['message' => __('api.r500')], 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/v1/user/getMyInfo",
     *      tags={"V1/user"},
     *      description="현재 로그인 사용자 정보를 소속 오퍼레이터 그룹과 함께 리턴한다.",
     *      @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string",
     *                      description="Access 토큰"
     *                  )
     *              )
     *          )
     *      ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string",
     *                      description="메시지"
     *                  ),
     *                  @OA\Property(
     *                      property="user",
     *                      type="object",
     *                      @OA\Property(
     *                          property="id",
     *                          type="integer",
     *                          description="회원 일련번호"
     *                      ),
     *                      @OA\Property(
     *                          property="user_id",
     *                          type="string",
     *                          description="회원 로그인 ID(암호화된 데이터)"
     *                      ),
     *                      @OA\Property(
     *                          property="name",
     *                          type="string",
     *                          description="사용자 이름"
     *                      ),
     *                      @OA\Property(
     *                          property="email",
     *                          type="string",
     *                          description="전자우편 주소"
     *                      ),
     *                      @OA\Property(
     *                          property="license_no",
     *                          type="string",
     *                          description="사업자등록번호"
     *                      ),
     *                      @OA\Property(
     *                          property="nickname",
     *                          type="string",
     *                          description="닉네임"
     *                      ),
     *                      @OA\Property(
     *                          property="car_no",
     *                          type="string",
     *                          description="차량번호"
     *                      ),
     *                      @OA\Property(
     *                          property="prefix",
     *                          type="integer",
     *                          description="차량 ID Prefix"
     *                      ),
     *                      @OA\Property(
     *                          property="boss_name",
     *                          type="string",
     *                          description="대표자"
     *                      ),
     *                      @OA\Property(
     *                          property="company_name",
     *                          type="string",
     *                          description="상호"
     *                      ),
     *                      @OA\Property(
     *                          property="telephone",
     *                          type="string",
     *                          description="대표전화"
     *                      ),
     *                      @OA\Property(
     *                          property="fax",
     *                          type="string",
     *                          description="팩스"
     *                      )
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMyInfo( Request $request ) {
        try {
            $user = $request->user();

            return response()->json([
                'message' => __('api.r200'),
                'user' => [
                    'id' => $user->id,
                    'user_id' => CryptDataS::encrypt( $user->user_id ),
                    'name' => $user->name,
                    'email' => $user->email,
                    'license_no' => $user->license_no,
                    'nickname' => $user->nickname,
                    'car_no' => $user->car_no,
                    'prefix' => $user->prefix,
                    'boss_name' => $user->boss_name,
                    'company_name' => $user->company_name,
                    'telephone' => $user->telephone,
                    'fax' => $user->fax,
                    'license_image' => !empty( $user->license_image ) ? asset( '/files/' . $user->license_image ) : '',
                    'car_registration_image' => !empty( $user->car_registration_image ) ? asset( '/files/' . $user->car_registration_image ) : ''
                ]
            ], 200);
        } catch ( \Exception $e ) {
            return response()->json(['message' => __('api.r500')], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/isUniqueCarNo",
     *     tags={"V1/user"},
     *     description="지정 차량번호가 유일한지 여부를 판단한다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰 (선택 사항, 회원정보 수정 시 필요)"
     *                  ),
     *                  @OA\Property(
     *                      property="car_no",
     *                      type="string",
     *                      description="차량번호"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string",
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 요청",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=463,
     *          description="중복된 자료",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @brief 차량번호의 중복 여부를 판단합니다.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function isUniqueCarNo( Request $request ) {
        $validator = Validator::make( $request->input(), [
            'car_no' => [ 'required', 'string', 'regex:/^[^\s]*$/' ]
        ]);

        if( $validator->fails() ) {
            return response()->json( ['message' => __('api.r400')], 400 );
        }

        $car_no = $request->input('car_no');
        try {
            $token = $request->input('api_token');
            if( !empty( $token ) ) {
                $cnt = User::where('car_no', $car_no )->count();
                $user = User::findByApiToken( $token );
                if( !$user ) return response()->json(['message' => __('api.r400' )], 400);
                else {
                    if( $user->car_no == $car_no && $cnt <= 2 ) return response()->json(['message' => __('api.r200')], 200);
                    else if( $cnt < 2 ) return response()->json(['message' => __('api.r200')], 200);
                    else return response()->json(['message' => __('api.r463')], 463);
                }
            }
            else {
                $cnt = User::where('car_no', $car_no )->count();
                if( $cnt >= 2 ) {
                    return response()->json(['message' => __('api.r463')], 463);
                }
                else return response()->json(['message' => __('api.r200')], 200);
            }
        }
        catch ( \Exception $e ) {
            return response()->json(['message' => __('api.r500')], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/login",
     *     tags={"V1/user"},
     *     description="User ID, Password, Device name, Device ID를 이용하으 로그인합니다. 만일 최초 로그인이거나 등록되지 않은 단말기의 경우 추가합니다 추가시 제한 범위를 초과한 경우 등록할 수 없으며 단말기가 존재하지 않음으로 리턴됩니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="user_id",
     *                      type="string",
     *                      description="사용자 ID (암호화된 데이터)"
     *                  ),
     *                  @OA\Property(
     *                      property="password",
     *                      type="string",
     *                      description="비밀번호 (암호화된 데이터)"
     *                  ),
     *                  @OA\Property(
     *                      property="device_name",
     *                      type="string",
     *                      description="단말기 이름"
     *                  ),
     *                  @OA\Property(
     *                      property="device_id",
     *                      type="string",
     *                      description="단말기 ID"
     *                  ),
     *                  @OA\Property(
     *                      property="fcm_device_token",
     *                      type="string",
     *                      description="FCM 단말기 토큰"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="role",
     *                      type="integer"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=403,
     *          description="이용 권한이 없음(기사만 이용 가능)",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=404,
     *          description="해당 단말기가 존재하지 않음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=406,
     *          description="수용할 수 없음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     */
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login( Request $request ) {
        $validator = Validator::make( $request->input(), [
            'user_id' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string'],
            'device_id' => ['required', 'string'],
            'fcm_device_token' => ['required', 'string']
        ] );

        if( $validator->fails() ) {
            return response()->json( ['message' => __('api.r400')], 400 );
        }

        $t_user_id = CryptDataS::decrypt( $request->input('user_id') );
        $t_password = CryptDataS::decrypt( $request->input('password') );
        if( empty( $t_user_id ) || empty( $t_password ) ) {
            return response()->json(['message' => __('api.r406')], 406 );
        }

        $t = [
            'user_id' => $t_user_id,
            'password' => $t_password
        ];

        if( $user = $this->getValidUserByArray( $t ) ) {
            if( $this->isExistDevice( $user, $request ) ) {
                $this->removeAccessToken( $user );
                $token = $this->genAccessToken( $user );
                $device = Device::getDeviceByUser( $user );
                if( $request->input('fcm_device_token') != $device->fcm_device_token ) {
                    $device->fcm_device_token = $request->input('fcm_device_token');
                    $device->save();
                }
                return response()->json(
                    [
                        'message' => __('api.r200'),
                        'api_token' => $user->api_token,
                        'access_token' => $token,
                        'role' => $this->getRoleIdsForUser( $user )
                    ],
                    200 );
            }
            else {
                if( $this->addDevice( $user, $request ) ) {
                    $this->removeAccessToken( $user );
                    $token = $this->genAccessToken( $user );

                    return response()->json(
                        [
                            'message' => __('api.r200'),
                            'api_token' => $user->api_token,
                            'access_token' => $token,
                            'role' => $this->getRoleIdsForUser( $user )
                        ],
                        200 );
                }
                else return response()->json( ['message' => __('api.r404')], 404);
            }
        }
        else {
            // 인증 실폐
            return response()->json( ['message' => __('api.r401')], 401 );
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/removeDevice",
     *     tags={"V1/user"},
     *     description="회원(기사)의 단말기를 삭제합니다. 그리고 지정 단말기로 접속한 엑세스 토큰은 삭제됩니다",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string",
     *                      description="Access 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="device_name",
     *                      type="string",
     *                      description="단말기 이름"
     *                  ),
     *                  @OA\Property(
     *                      property="device_id",
     *                      type="string",
     *                      description="단말기 토큰"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=404,
     *          description="단말기 정보 없음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     */
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeDevice( Request $request ) {
        $user = $request->user();

        $validator = Validator::make( $request->input(), [
            'device_id' => ['required', 'string'],
            'device_name' => ['required', 'string']
        ]);

        if( $validator->fails() ) {
            return response()->json( ['message' => __('api.r4001')], 400 );
        }
        else {
            $device = $this->getDeviceFromRequest( $user, $request );
            if( !$device ) return response()->json( ['message' => __('api.r404')], 404 );
            else {
                $device->delete();
                $this->removeAccessToken( $request->user() );
                return response()->json(['message' => __('api.r200')], 200);
            }
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/logout",
     *     tags={"V1/user"},
     *     description="API, 엑세스 토큰을 이용하여 로그아웃합니다 엑세스 토큰은 삭제됩니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string",
     *                      description="엑세스 토큰"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=460,
     *          description="로그인 상태가 아님",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=461,
     *          description="잘 못된 엑세스 토큰임",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=462,
     *          description="만료된 엑세스 토큰임",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @brief 접속을 종료한다.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout( Request $request ) {
        $this->removeAccessToken( $request->user() );
        return response()->json(['message' => __('api.r200')], 200 );
    }

    /**
     * @OA\Post(
     *     path="/v1/user/loginByApiToken",
     *     tags={"V1/user"},
     *     description="API 토큰을 이용하여 자동 로그인 또는 재접속합니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="fcm_device_token",
     *                      type="string",
     *                      description="단말기 FCM 토큰"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="role",
     *                      type="integer"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     */
    /**
     * @param $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function loginByApiToken( Request $request ) {
        $user = $request->user();
        $this->removeAccessToken( $user );
        $token = $this->genAccessToken( $user );

        if( !empty( $fcm_token = $request->input('fcm_device_token') ) ) {
            $device = Device::getDeviceByUser( $user );
            if( $device->fcm_device_token != $fcm_token ) {
                $device->fcm_device_token = $fcm_token;
                $device->save();
            }
        }

        return response()->json(
            [
                'message' => __('api.r200'),
                'access_token' => $token,
                'role' => $this->getRoleIdsForUser( $user )
            ],
            200 );
    }

    /**
     * @OA\Post(
     *     path="/v1/user/checkValidAccessToken",
     *     tags={"V1/user"},
     *     description="엑세스 토큰의 유효성을 검사합니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string",
     *                      description="Access 토큰"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=460,
     *          description="로그인 상태가 아님",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=461,
     *          description="잘 못된 엑세스 토큰임",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=462,
     *          description="만료된 엑세스 토큰임",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkValidAccessToken( Request $request ) {
        return response()->json(['message' => __('api.r200')], 200 );
    }

    /**
     * @OA\Post(
     *     path="/v1/user/setDeviceFCMToken",
     *     tags={"V1/user"},
     *     description="파이어베이스 클라우드 메시징 서버스 연동에 필요한 단말기 토큰을 설정합니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="device_name",
     *                      type="string",
     *                      description="단말기 이름"
     *                  ),
     *                  @OA\Property(
     *                      property="device_id",
     *                      type="string",
     *                      description="단말기 ID"
     *                  ),
     *                  @OA\Property(
     *                      property="fcm_device_token",
     *                      type="string",
     *                      description="FCM 단말기 토튼"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=403,
     *          description="이용 권한이 없음(기사만 이용 가능)",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=404,
     *          description="해당 단말기가 존재하지 않음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @brief 파이어베이스 클라우드 메시징 서버스 연동에 필요한 단말기 토큰을 설정합니다.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function setDeviceFCMToken( Request $request ) {
        $validator = Validator::make($request->input(), [
            'device_name' => ['required', 'string'],
            'device_id' => ['required', 'string'],
            'fcm_device_token' => ['required', 'string']
        ]);

        if( $validator->fails() ) {
            return response()->json(['message' => __('api.r400')], 400);
        }
        else {
            $device = $this->getDeviceFromRequest( $request->user(), $request );
            if( !$device ) return response()->json(['message' => __('api.r404')], 404);
            else {
                $device->fcm_device_token = $request->input('fcm_device_token');
                $device->save();
                return response()->json(['message' => __('api.r200')], 200);
            }
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/getDeviceFCMToken",
     *     tags={"V1/user"},
     *     description="해당 단말기의 FCM 단말기 토큰을 리턴합니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string",
     *                      description="Access 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="device_name",
     *                      type="string",
     *                      description="단말기 이름"
     *                  ),
     *                  @OA\Property(
     *                      property="device_id",
     *                      type="string",
     *                      description="단말기 ID"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="fcm_device_token",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=403,
     *          description="이용 권한이 없음(기사만 이용 가능)",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=404,
     *          description="해당 단말기가 존재하지 않음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @brief FCM 단말기 토큰 리턴
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeviceFCMToken( Request $request ) {
        $validator = Validator::make($request->input(), [
            'device_name' => ['required', 'string'],
            'device_id' => ['required', 'string']
        ]);

        if( $validator->fails() ) {
            return response()->json(['message' => __('api.r400')]);
        }
        else {
            $device = $this->getDeviceFromRequest( $request->user(), $request );
            if( !$device ) return response()->json(['message' => __('api.r404')], 404);
            else {
                return response()
                    ->json([
                        'message' => __('api.r200'),
                        'fcm_device_token' => $device->fcm_device_token
                    ], 200);
            }
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/setPassword",
     *     tags={"V1/user"},
     *     description="비밀번호를 변경합니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="password",
     *                      type="string",
     *                      description="비밀번호 (암호화된 데이터)"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=406,
     *          description="수용할 수 없음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function setPassword( Request $request ) {
        $validator = Validator::make( $request->input(), [
            'password' => ['required', 'string']
        ]);

        if( $validator->fails() ) {
            return response()->json(['message' => __('api.r400')], 400);
        }

        $t_password = CryptDataS::decrypt( $request->input('password') );
        if( empty( $t_password ) ) {
            return response()->json(['message' => __('api.r406')], 406);
        }

        $user = $request->user();
        $user->password = Hash::make( $t_password );
        $user->save();
        return response()->json(['message' => __('api.r200')], 200);
    }

    /**
     * @OA\Post(
     *     path="/v1/user/setPasswordByUserId",
     *     tags={"V1/user"},
     *     description="비밀번호를 변경합니다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="user_id",
     *                      type="string",
     *                      description="사용자 ID (암호화된 데이터)"
     *                  ),
     *                  @OA\Property(
     *                      property="password",
     *                      type="string",
     *                      description="비밀번호 (암호화된 데이터)"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=406,
     *          description="수용할 수 없음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function setPasswordByUserId( Request $request ) {
        $validator = Validator::make( $request->input(), [
            'user_id' => ['required', 'string'],
            'password' => ['required', 'string']
        ]);

        if( $validator->fails() ) {
            return response()->json(['message' => __('api.r400')], 400);
        }

        $t_user_id = CryptDataS::decrypt( $request->input('user_id') );
        $t_password = CryptDataS::decrypt( $request->input('password') );
        if( empty( $t_user_id ) || empty( $t_password ) ) {
            return response()->json(['message' => __('api.r406')], 406 );
        }

        $user = User::findByUserId( $t_user_id );
        $user->password = Hash::make( $t_password );
        $user->save();
        return response()->json(['message' => __('api.r200')], 200);
    }

    /**
     * @OA\Post(
     *      path="/v1/initMain",
     *      tags={"V1/user"},
     *      description="앱의 매인화면 출력 시 호출되는 것으로 최근공지 ID, 사용자 역할, 차량번호, 챠랑 ID 접두어를 리턴한다.",
     *      @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰",
     *                  ),
     *                  @OA\Property(
     *                      property="access_token",
     *                      type="string",
     *                      description="Access 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="last_ids",
     *                      type="string",
     *                      description="각 게시판별 마지막 열람정보 (게시판ID:열람 게시물 마지막 ID로된 쌍의 데이터 각 데이터는 컴마로 구분)"
     *                  )
     *              )
     *          )
     *      ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="notification_id",
     *                      type="string",
     *                      description="최근 공지사항 ID"
     *                  ),
     *                  @OA\Property(
     *                      property="id",
     *                      type="ingeger",
     *                      description="사용자 시리얼 번호"
     *                  ),
     *                  @OA\Property(
     *                      property="role",
     *                      type="ingeger",
     *                      description="회원 역할 번호"
     *                  ),
     *                  @OA\Property(
     *                      property="car_no",
     *                      type="string",
     *                      description="차량번호"
     *                  ),
     *                  @OA\Property(
     *                      property="car_id_prefix",
     *                      type="string",
     *                      description="소속 회사에 따른 차량 접두어"
     *                  ),
     *                  @OA\Property(
     *                      property="permissions",
     *                      type="array",
     *                      description="회원에게 주어진 권한 목록",
     *                      @OA\Items(
     *                          type="string"
     *                      )
     *                  ),
     *                  @OA\Property(
     *                      property="filled_required",
     *                      type="boolean",
     *                      description="회원 유형별 필수 필드를 모두 체웠는지 여부의 블린 값"
     *                  ),
     *                  @OA\Property(
     *                      property="new_content_counts",
     *                      type="string",
     *                      description="각 게시판별 미열람 개시물 수정보 (게시판ID:미열람 게시물수로된 쌍의 데이터 각 데이터는 컴마로 구분)"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=403,
     *          description="이용 권한이 없음(기사만 이용 가능)",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initMain( Request $request ) {
        $last_ids = $request->input('last_ids');

        try {
            $user = $request->user();
            $role = $this->getRoleIdsForUser( $user );
            $notification = Notification::orderBy('created_at', 'desc')->take(1)->get()->first();
            $notification_id = $notification ? $notification->id : 0;

            $result = [
                'message' => __('api.r200'),
                'notification_id' => $notification_id,
                'id' => $user->id,
                'role' => $role,
                'car_no' => $user->car_no,
                'car_id_prefix' => $user->prefix,
                'permissions' => $user->getAllPermissionNames(),
                'filled_required' => $user->isFilledRequired()
            ];
            if( !empty( $last_ids ) ) {
                $new_content_counts = BoardContent::getNewContentCount( $last_ids );
                if( !empty( $new_content_counts ) ) $result['new_content_counts'] = $new_content_counts;
            }

            return response()->json( $result, 200);
        }
        catch ( \Exception $e ) {
            return response()->json([
                'message' => __('api.r500')
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/isAbleFeature",
     *     tags={"V1/user"},
     *     description="각 기능 사용에 대한 가능 여부를 판단하여 리턴한다.",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="permission",
     *                      type="string",
     *                      description="권한이름"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=406,
     *          description="수용할 수 없음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function isAbleFeature( Request $request ) {
        try{
            $user = $request->user();
            if( empty( $permission = $request->input('permission') ) ) {
                return response()->json(['message' => __('api.r400')], 400);
            }

            if( $user->hasPermissionTo( $permission ) ) {
                return response()->json(['message' => __('api.r200')], 200);
            }
            else {
                if( $user->hasPermissionTo('system_configuration') ) {
                    return response()->json(['message' => __('api.r200')], 200);
                }
                return response()->json(['message' => __('api.r406')], 406);
            }
        } catch ( \Exception $e ) {
            return response()->json(['message' => __('api.r500')], 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/v1/user/putLicenseImageByMultipart",
     *      tags={"V1/user"},
     *      description="회원의 사업자등록증 또는 차량등록증 이미지를 업로드한다. 사업자등록증 이미지 또는 차량등록증 이미지 중 하나는 반드시 입력되어야 합니다.",
     *      @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      description="API 토큰",
     *                      type="string"
     *                  ),
     *                  @OA\Property(
     *                      property="license_image",
     *                      description="사업자등록증 이미지",
     *                      type="string",
     *                      format="binary"
     *                  ),
     *                  @OA\Property(
     *                      property="car_registration_imags",
     *                      description="차량등록증 이미지",
     *                      type="string",
     *                      format="binary"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 요청",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=406,
     *          description="수용할 수 없음",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function putLicenseImageByMultipart( Request $request ) {
        $user = $request->user();
        $user_id = $user->user_id;
        $today = new Carbon();
        $datetime = $today->format('YmdHis');

        $car_registration_image = $request->file('car_registration_imags');
        $license_image = $request->file('license_image');

        $flag = false;

        try {
            if( !empty( $car_registration_image ) ) {
                if( is_array( $car_registration_image ) ) {
                    return response()->json(['message' => __('api.r400'), 'error' => 'is_array car_registration'], 400);
                }
                if( !empty( $user->car_registration_image ) ) {
                    unlink( public_path() . '/files/' . $user->car_registration_image );
                }

                $extension = $car_registration_image->getClientOriginalExtension();
                $user->car_registration_image = $car_registration_image->storeAs( 'car_registration_images', $user_id . $datetime . '.' . $extension, 'public' );
                $flag = true;
            }

            if( !empty( $license_image ) ) {
                if( is_array( $license_image ) ) {
                    return response()->json(['message' => __('api.r400'), 'error' => 'is_array license'], 400);
                }
                if( !empty( $user->license_image ) ) {
                    unlink( public_path() . '/files/' . $user->license_image );
                }
                $extension = $license_image->getClientOriginalExtension();
                $user->license_image = $license_image->storeAs( 'license_images', $user_id . $datetime . '.' . $extension , 'public');
                $flag = true;
            }

            if( $flag ) $user->save();

            if( $flag ) return response()->json(['message' => __('api.r200')], 200);
            else return response()->json(['message' => __('api.r400'), 'error' => 'no data'], 400);
        } catch ( \Exception $e ) {
            return response()->json(['message' => __('api.r500') . $e->getMessage(), 'error' => $e->getTrace()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/v1/user/cancel",
     *     tags={"V1/user"},
     *     description="회원 탈테 요청 (요청 송공 시 회원정보 완전 삭제, 콘텐츠 소유자 NULL로 처리)",
     *     @OA\RequestBody(
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="api_token",
     *                      type="string",
     *                      description="API 토큰"
     *                  ),
     *                  @OA\Property(
     *                      property="permission",
     *                      type="string",
     *                      description="권한이름"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="성공",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=400,
     *          description="잘 못된 접근",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="인증 실폐",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *          response=500,
     *          description="서버오류",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="message",
     *                      type="string"
     *                  )
     *              )
     *          )
     *     )
     * )
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function CancelUser( Request $request ) {
        $user = $request->user();

        try {
            $user->delete();
            return response()->json(['message' => __('api.r200')], 200);
        } catch ( \Exception $e ) {
            return response()->json(['message' => __('api.r500')], 500);
        }
    }
}
