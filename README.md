# Open Api(Swagger) 사용 경험 정리

## 발표내용
1. 문서화 도구 사용 계기
2. Open Api 선택 이유
3. Open Api 기본 구조와 Swagger-PHP
4. Swagger-PHP 장점과 단점
6. 문서화 최적화, 유지보수, 클린 아키택쳐

## 문서화 도구 사용 계기
부산 항만 컨테이너 화물 운송관련 앱 개발 용역을 수주하여 백엔드 개발자, 앱(안드로이드) 개발자, 디자이너 3인 프로젝트를 진행했고, 앱 개발자가 API 문서화를 요청

## Swagger 선택 이유
* 표준화된 API 문서화 규격
* 다양한 도구와 통합
* 다양한 언어 및 프레임워크 지원

## Open Api 기본 구조와 Swagger-PHP
Open Api 기본 구조에서 아래와 같이 구분할 수 있습니다.

* 메타데이터
* 서버 정보
* Api 경로 정보
* 컴포넌트(파라메타, 응답, 요청 Body 데이터, 기타 입출력 모델 스크마)
* 인증
* 테그

### 메타데이터
```yml
info:
  title: Sample API
  description: Optional multiline or single-line description in [CommonMark](http://commonmark.org/help/) or HTML.
  version: 0.1.9
```

```php
/**
 * @OA\Info (
 *      version="1.0",
 *      title="더 링코 코어(The Linko Core) Api 서비스",
 *      description="본 API 서비스는 자체 시비스인 차비스 를 위해 개발되었습니다.",
 *      @OA\Contact(
 *          email="sh.jang@sohocode.kr"
 *      ),
 *      @OA\License(
 *          name="Private"
 *      )
 * )
 * @OA\ExternalDocumentation(
 *     description="참조 내용",
 *     url="/references"
 * )
 */
```
### 서버정보
```yml
servers:
  - url: http://api.example.com/v1
    description: Optional server description, e.g. Main (production) server
  - url: http://staging-api.example.com
    description: Optional server description, e.g. Internal staging server for testing
```

```php
/**
 * @OA\Server(
 *      url="https://public.the-linko-core.wd/api/v1",
 *      description="백엔드 로컬 개발환경 전용"
 * )
 * @OA\Server(
 *      url="https://tlk.sohocode.kr/api/v1",
 *      description="프론트엔드 테스트서버"
 * )
 */
```

### Api 경로정보
```yml
paths:
  /users:
    get:
      summary: Returns a list of users.
      description: Optional extended description in CommonMark or HTML
      responses:
        '200':
          description: A JSON array of user names
          content:
            application/json:
              schema: 
                type: array
                items: 
                  type: string
```

```php
/**
 * 실무자 계정을 생성한다.
 * @param RequestJoinManagerOperator $request
 * @return JsonResponse
 * @OA\Post (
 *     path="/manager/joinOperator",
 *     tags={"manager"},
 *     security={{"BearerAuth":{}, "AccessTokenAuth": {}}},
 *     @OA\RequestBody (
 *          @OA\MediaType (
 *              mediaType="multipart/form-data",
 *              @OA\Schema (
 *                  allOf={
 *                      @OA\Schema (ref="#/components/schemas/join_password"),
 *                      @OA\Schema (ref="#/components/schemas/join_manager_opwerator"),
 *                  },
 *                  required={"password"}
 *              )
 *          )
 *     ),
 *     @OA\Response (response=200, ref="#/components/responses/200"),
 *     @OA\Response (response=400, ref="#/components/responses/400"),
 *     @OA\Response (response=401, ref="#/components/responses/401"),
 *     @OA\Response (response=403, ref="#/components/responses/403"),
 *     @OA\Response (response=500, ref="#/components/responses/500")
 * )
 */
```

### 파라메터 정보
```yml
/users/{userId}:
    get:
        summary: Returns a user by ID.
        parameters:
        - name: userId
            in: path
            required: true
            description: Parameter description in CommonMark or HTML.
            schema:
            type : integer
            format: int64
            minimum: 1
        responses: 
        '200':
            description: OK
```

### Request Body 데이터 정보
```yml
paths:
  /users:
    post:
      summary: Creates a user.
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                username:
                  type: string
      responses: 
        '201':
          description: Created
```

### 응답정보
```yml
paths:
  /users/{userId}:
    get:
      summary: Returns a user by ID.
      ....
      responses:
        '200':
          description: A user object.
          content:
            application/json:
              schema:
                type: object
                properties:
                  id:
                    type: integer
                    format: int64
                    example: 4
                  name:
                    type: string
                    example: Jessica Smith
        '400':
          description: The specified user ID is invalid (not a number).
        '404':
          description: A user with the specified ID was not found.
        default:
          description: Unexpected error
```

### 입출력 모델 스크마
```yml
components:
  schemas:
    User:
      type: object
      properties:
        id:
          type: integer
          example: 4
        name:
          type: string
          example: Arthur Dent
      # Both properties are required
      required:  
        - id
        - name
```

```php
/**
 * 유효성 검사 오류 시 JsonResponse 객체를 리턴하도록 한다.
 * @return array
 * @OA\Schema(
 *     schema="updatable_visa_document_type",
 *     title="등록 가능한 비자발급시 필요한 문서 정보 항목",
 *     @OA\Property(
 *          property="name",
 *          type="string",
 *          description="문서유형 이름"
 *     ),
 *     @OA\Property(
 *          property="en_name",
 *          type="string",
 *          description="문서유형 이름(영문)"
 *     ),
 *     @OA\Property(
 *          property="description",
 *          type="string",
 *          description="유형 설명"
 *     ),
 *     @OA\Property(
 *          property="en_description",
 *          type="string",
 *          description="유형 설명(영문)"
 *     ),
 *     @OA\Property(
 *          property="active",
 *          type="integer",
 *          enum={"0","1"},
 *          description="사용여부"
 *     ),
 *     required={"name", "en_name", "active"}
 * )
 */
```

### 인증
```yml
components:
  securitySchemes:
    BasicAuth:
      type: http
      scheme: basic
security:
  - BasicAuth: []
```

```php
/**
 * @OA\securityScheme(
 *      securityScheme="BearerAuth",
 *      type="http",
 *      scheme="Bearer",
 * )
 *
 * @OA\securityScheme(
 *      securityScheme="AccessTokenAuth",
 *      in="header",
 *      type="apiKey",
 *      name="X-ACCESS-TOKEN",
 * )
 */
```

### 테그
```yml
tags:
  - name: pets
    description: Everything about your Pets
    externalDocs:
      url: http://docs.my-api.com/pet-operations.htm
  - name: store
    description: Access to Petstore orders
    externalDocs:
      url: http://docs.my-api.com/store-orders.htm
```

```php
/**
 * @OA\Tag(
 *     name="V1/user",
 *     description="사용자 인증"
 * )
 */
```

## Swagger-PHP 장점과 단점
* 장점 : 코드와 함께 주석으로 표기하여 코드 수정과 문서화 내용 수정이 편리합니다.
* 단점 : 문서화 내용이 많아 코드의 가독성이 떨어진다. 최적화를 통해 문서화 내용을 컴포넌트로 분리하여 표기할 필요가 있습니다.

[단점 예시](./src/ApiTokenController.php)

### PHP 7.x에서는 가능하나 8.x에서는 지원하지 않는 것
Swagger-PHP 버전 4로 접어들면서 독립적인 주석은 더 이상 지원하지 않게 되고, 8.x의 Attribute와 같은 제약을 받게 되면서 아래와 같은 형태로 문서화하는 불가능해졌습니다.
```php
/**
 * @OA\Info(
 *     version="1,2",
 *     title="Trans App API",
 *     description="물류관리 어플리케이션과 연동하기 위한 API입니다. 이 문서에는 1.0과 2.0이 통합되어 있습니다. 각 버전별 태그로 분리된 API를 참고하십시오. 각 버전의 경로에 주의하세요.",
 *     @OA\Contact(
 *          email="sh.jang@msmglo......bal.kr"
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
```

## 최적화, 유지보수, 클린 아키택져

### 최적화 규칙
아래의 규칙은 Laravel을 기준으로 합니다.

* 