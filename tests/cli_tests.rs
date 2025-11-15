#[test]
fn cli_tests() {
    unsafe { std::env::set_var("MOVEPRESS_MASK_TEMP", "1") };
    trycmd::TestCases::new().case("tests/cmd/*.trycmd");
}
